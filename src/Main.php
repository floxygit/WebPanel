<?php

declare(strict_types=1);

namespace floxygit\WebPanel;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\player\Player;
use pocketmine\console\ConsoleCommandSender;

class Main extends PluginBase
{
    private $socket;
    private $appPassword;
    public array $commandOutputs = [];

    protected function onEnable(): void
    {
        $config = $this->getConfig();
        if (!$config->exists("appPassword")) {
            $config->set("appPassword", "1234");
            $config->save();
        }
        $this->appPassword = (string)$config->get("appPassword", "1234");

        $port = (int)$config->get("appPort", 7800);
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        @socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (@socket_bind($this->socket, "0.0.0.0", $port) && @socket_listen($this->socket)) {
            $this->getLogger()->info("Web Server was started on Port {$port}.");
            socket_set_nonblock($this->socket);
            $this->getScheduler()->scheduleRepeatingTask(new class($this->socket, $this->appPassword, $this) extends Task {
                public function __construct(private $s, private $password, private Main $plugin) {}

                public function onRun(): void
                {
                    if (($client = @socket_accept($this->s)) === false) {
                        return;
                    }

                    $buf = @socket_read($client, 8192);
                    if (!$buf) {
                        @socket_close($client);
                        return;
                    }

                    $authenticated = strpos($buf, "webpanel_auth=1") !== false;

                    if (!$authenticated) {
                        if (preg_match('/^POST /', $buf) && preg_match('/pass=([^&\r\n]+)/', $buf, $m)) {
                            $submitted = urldecode(trim($m[1]));
                            if ($submitted === $this->password) {
                                $this->sendResponse($client, "HTTP/1.1 302 Found\r\nSet-Cookie: webpanel_auth=1; Path=/; Max-Age=86400\r\nLocation: /players\r\n\r\n");
                                @socket_close($client);
                                return;
                            }
                        }

                        $loginHtml = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>WebPanel Login</title><script src="https://cdn.tailwindcss.com"></script><style>@import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap\');body { font-family: \'Inter\', sans-serif; background: #0f172a; color: white; margin: 0; height: 100vh; display: flex; align-items: center; justify-content: center; }.card { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); }</style></head><body><div class="card w-full max-w-md mx-4 p-10 rounded-3xl shadow-2xl"><div class="text-center mb-10"><span class="text-4xl font-black gradient-text tracking-tighter" style="background: linear-gradient(135deg, #60a5fa, #c084fc); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">WebPanel</span></div><form method="post" class="space-y-6"><div><input type="password" name="pass" placeholder="Enter password" class="w-full bg-[#1f2937] border border-[#4b5563] text-white placeholder:text-[#9ca3af] rounded-2xl px-6 py-4 focus:outline-none focus:border-[#60a5fa] focus:bg-[#334155] text-lg" required autocomplete="off"></div><button type="submit" class="w-full bg-[#60a5fa] hover:bg-[#3b82f6] text-white font-bold py-4 rounded-2xl text-lg transition">Login</button></form><p class="text-center text-white/40 text-sm mt-8">Password from config.yml</p></div></body></html>';

                        $this->sendResponse($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n" . $loginHtml);
                        @socket_close($client);
                        return;
                    }

                    if (preg_match('#^GET /api/players #', $buf)) {
                        $players = [];
                        foreach (Server::getInstance()->getOnlinePlayers() as $p) {
                            $name = $p->getName();
                            $players[] = [
                                'name'    => $name,
                                'initial' => strtoupper(substr($name, 0, 1)),
                                'isOp'    => Server::getInstance()->isOp($name),
                            ];
                        }
                        $json = json_encode([
                            'count'   => count($players),
                            'players' => $players,
                        ]);
                        $this->sendResponse($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n" . $json);
                        @socket_close($client);
                        return;
                    }

                    if (preg_match('#^GET /api/console #', $buf)) {
                        $logFile = Server::getInstance()->getDataPath() . "server.log";
                        $logLines = [];
                        if (file_exists($logFile)) {
                            $logLines = array_slice(file($logFile), -15);
                            $logLines = array_map(function($line) {
                                return \pocketmine\utils\TextFormat::clean(rtrim($line));
                            }, $logLines);
                        }

                        $json = json_encode([
                            'log' => $logLines,
                            'outputs' => $this->plugin->commandOutputs
                        ]);
                        $this->sendResponse($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n" . $json);
                        @socket_close($client);
                        return;
                    }

                    if (preg_match('/GET \/action\/(\w+)\/([^\/\s?]+)/', $buf, $m)) {
                        $action = $m[1];
                        $target = urldecode($m[2]);

                        $player = Server::getInstance()->getPlayerExact($target);

                        if ($player instanceof Player || $action === "ban") {
                            switch ($action) {
                                case "op":    Server::getInstance()->addOp($target); break;
                                case "deop":  Server::getInstance()->removeOp($target); break;
                                case "kick":  $player?->kick("WebPanel"); break;
                                case "ban":
                                    Server::getInstance()->getNameBans()->addBan($target, "WebPanel");
                                    $player?->kick("WebPanel");
                                    break;
                            }
                        }
                        $this->sendResponse($client, "HTTP/1.1 302 Found\r\nLocation: /players\r\n\r\n");
                        @socket_close($client);
                        return;
                    }

                    if (preg_match('#^POST /console #', $buf) && preg_match('#command=([^&\r\n]+)#', $buf, $m)) {
                        $cmd = urldecode(trim($m[1]));
                        if ($cmd !== '') {
                            $server = Server::getInstance();

                            $this->plugin->commandOutputs[] = [
                                'type' => 'command',
                                'time' => date('H:i:s'),
                                'text' => $cmd
                            ];

                            $sender = new class($server, $server->getLanguage(), $this->plugin) extends ConsoleCommandSender {
                                public function __construct(Server $server, \pocketmine\lang\Language $language, private Main $plugin) {
                                    parent::__construct($server, $language);
                                }

                                public function sendMessage(string|\pocketmine\lang\Translatable $message) : void {
                                    if ($message instanceof \pocketmine\lang\Translatable) {
                                        $message = $this->getLanguage()->translate($message);
                                    }

                                    $clean = \pocketmine\utils\TextFormat::clean($message);
                                    $this->plugin->commandOutputs[] = [
                                        'type' => 'output',
                                        'time' => date('H:i:s'),
                                        'text' => $clean
                                    ];

                                    parent::sendMessage($message);
                                }
                            };

                            $server->dispatchCommand($sender, $cmd);

                            if (count($this->plugin->commandOutputs) > 50) {
                                $this->plugin->commandOutputs = array_slice($this->plugin->commandOutputs, -50);
                            }
                        }
                        $this->sendResponse($client, "HTTP/1.1 302 Found\r\nLocation: /console\r\n\r\n");
                        @socket_close($client);
                        return;
                    }

                    $html = $this->plugin->render($buf);
                    $this->sendResponse($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n" . $html);
                    @socket_close($client);
                }

                private function sendResponse($client, string $content): void
                {
                    @socket_write($client, $content, strlen($content));
                }
            }, 1);
        } else {
            $this->getLogger()->error("Web Server was unable to start on Port {$port}.");
        }
    }

    protected function onDisable(): void
    {
        if ($this->socket) {
            @socket_close($this->socket);
        }
    }

    public function render(string $buffer): string
    {
        $page = "home";
        if (preg_match('#^GET /players\s#', $buffer)) {
            $page = "players";
        }
        if (preg_match('#^GET /console\s#', $buffer)) {
            $page = "console";
        }

        $texts = [
            "welcome" => "Welcome!",
            "sub"     => "Your dashboard is ready for action.",
            "pm"      => "Player Management",
            "dash"    => "Dashboard",
            "console" => "Console",
            "search"  => "Search players...",
            "cmdplaceholder" => "Enter command (e.g. say Hello everyone)",
            "send" => "Send",
        ];

        $liveScript = '';

        if ($page === "players") {
            $liveScript = '
            <script>
                const playersContainer = document.getElementById("players-container");
                const countBadge = document.getElementById("online-count");
                const searchInput = document.getElementById("player-search");

                let allPlayers = [];

                async function fetchPlayers() {
                    try {
                        const res = await fetch("/api/players");
                        const data = await res.json();
                        allPlayers = data.players;
                        countBadge.textContent = data.count + " online";
                        renderPlayers(allPlayers);
                    } catch (e) { console.error("Fetch failed", e); }
                }

                function renderPlayers(players) {
                    let html = "";
                    if (players.length === 0) {
                        html = `<div class="col-span-full text-center py-16 opacity-30 italic">No players found.</div>`;
                    } else {
                        players.forEach(p => {
                            const opClass = p.isOp ? "btn-orange" : "btn-blue";
                            const opAction = p.isOp ? "deop" : "op";
                            const opText  = p.isOp ? "De-OP" : "OP";
                            const nameEnc = encodeURIComponent(p.name);

                            html += `
                            <div class="user-card">
                                <div class="flex flex-col items-center mb-5">
                                    <div class="w-20 h-20 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-3xl font-black text-white shadow-xl border-2 border-white/20">
                                        ${p.initial}
                                    </div>
                                </div>
                                <div class="text-center font-bold text-white mb-5 tracking-tight text-lg break-all">
                                    ${p.name}
                                </div>
                                <div class="grid grid-cols-1 gap-3 px-2 sm:px-3">
                                    <a href="/action/${opAction}/${nameEnc}" class="action-btn ${opClass} py-3 text-sm">
                                        ${opText}
                                    </a>
                                    <div class="grid grid-cols-2 gap-3">
                                        <a href="/action/kick/${nameEnc}" class="action-btn btn-yellow py-3 text-sm">Kick</a>
                                        <a href="/action/ban/${nameEnc}" class="action-btn btn-red py-3 text-sm">Ban</a>
                                    </div>
                                </div>
                            </div>`;
                        });
                    }
                    if(document.activeElement !== searchInput) playersContainer.innerHTML = html;
                }

                function filterPlayers() {
                    const term = searchInput.value.toLowerCase().trim();
                    if (!term) {
                        renderPlayers(allPlayers);
                        return;
                    }
                    const filtered = allPlayers.filter(p => p.name.toLowerCase().includes(term));
                    renderPlayers(filtered);
                }

                fetchPlayers();
                setInterval(fetchPlayers, 2000);

                if (searchInput) {
                    searchInput.addEventListener("input", filterPlayers);
                }
            </script>';
        }

        if ($page === "console") {
            $liveScript = '
            <script>
                const consoleOutput = document.getElementById("console-output");
                const cmdInput = document.getElementById("cmd-input");
                let isScrolledToBottom = true;

                consoleOutput.addEventListener("scroll", () => {
                    isScrolledToBottom = consoleOutput.scrollHeight - consoleOutput.clientHeight <= consoleOutput.scrollTop + 10;
                });

                async function fetchConsole() {
                    try {
                        const res = await fetch("/api/console");
                        const data = await res.json();
                        let html = "";

                        if (data.log.length > 0) {
                            html += `<div class="text-[10px] text-gray-500 uppercase tracking-widest mb-2 border-b border-[#2d2d3d] pb-1">Server Log</div>`;
                            data.log.forEach(line => {
                                html += `<div class="text-[13px] text-gray-400 font-mono leading-relaxed">${line}</div>`;
                            });
                        }

                        if (data.outputs.length > 0) {
                            html += `<div class="text-[10px] text-blue-500 uppercase tracking-widest mt-6 mb-2 border-b border-[#2d2d3d] pb-1">Web Session</div>`;
                            data.outputs.forEach(item => {
                                if (item.type === "command") {
                                    html += `<div class="text-[13px] text-white mt-3 font-bold flex items-start gap-2">
                                        <span class="text-gray-500 font-normal shrink-0">[${item.time}]</span>
                                        <span class="text-green-400 shrink-0">❯</span>
                                        <span class="break-all">${item.text}</span>
                                    </div>`;
                                } else {
                                    html += `<div class="text-[13px] text-gray-300 ml-1 flex items-start gap-2">
                                        <span class="text-gray-600 text-[11px] mt-[3px] shrink-0">[${item.time}]</span>
                                        <span class="whitespace-pre-wrap break-words">${item.text}</span>
                                    </div>`;
                                }
                            });
                        }

                        consoleOutput.innerHTML = html;
                        if (isScrolledToBottom) {
                            consoleOutput.scrollTop = consoleOutput.scrollHeight;
                        }
                    } catch (e) { console.error("Console fetch failed", e); }
                }

                fetchConsole();
                setInterval(fetchConsole, 1500);

                document.getElementById("cmd-form").addEventListener("submit", async (e) => {
                    e.preventDefault();
                    const cmd = cmdInput.value.trim();
                    if (!cmd) return;

                    cmdInput.disabled = true;
                    try {
                        await fetch("/console", {
                            method: "POST",
                            headers: {"Content-Type": "application/x-www-form-urlencoded"},
                            body: "command=" + encodeURIComponent(cmd)
                        });
                        cmdInput.value = "";
                        await fetchConsole();
                        consoleOutput.scrollTop = consoleOutput.scrollHeight;
                    } catch (e) { console.error("Command send failed", e); }
                    cmdInput.disabled = false;
                    cmdInput.focus();
                });
            </script>';
        }

        if ($page === "home") {
            $mainContent = '<div class="text-center px-4"><h1 class="text-5xl sm:text-7xl md:text-8xl font-black mb-6 gradient-text tracking-tighter">'.$texts["welcome"].'</h1><p class="text-white/50 text-base sm:text-lg">'.$texts["sub"].'</p></div>';
        } elseif ($page === "players") {
            $mainContent = '<div class="w-full max-w-7xl px-4 sm:px-6 lg:px-8"><div class="flex flex-col gap-6 mb-10"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5"><h2 class="text-2xl sm:text-3xl font-black tracking-tight">'.$texts["pm"].'</h2><div id="online-count" class="px-4 py-1.5 rounded-full bg-blue-500/10 text-blue-400 text-xs sm:text-sm font-bold border border-blue-500/20 whitespace-nowrap text-center">0 online</div></div><input id="player-search" type="text" placeholder="'.$texts["search"].'" class="w-full sm:w-72 bg-[#1f2937] border border-[#4b5563] text-white placeholder:text-[#9ca3af] rounded-2xl px-6 py-4 focus:outline-none focus:border-[#60a5fa] focus:bg-[#334155] transition-all"></div><div id="players-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 sm:gap-6"><div class="col-span-full text-center py-16 opacity-30 italic">Loading players...</div></div></div>';
        } elseif ($page === "console") {
            $mainContent = '
            <div class="w-full max-w-5xl px-4 sm:px-6 lg:px-8">
                <h2 class="text-2xl sm:text-3xl font-black tracking-tight mb-6">'.$texts["console"].'</h2>
                <div class="bg-[#0f0f14] border border-[#2d2d3d] rounded-xl overflow-hidden shadow-2xl flex flex-col">
                    <div class="bg-[#1a1a24] px-4 py-3 flex items-center gap-2 border-b border-[#2d2d3d]">
                        <div class="w-3 h-3 rounded-full bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.4)]"></div>
                        <div class="w-3 h-3 rounded-full bg-yellow-500 shadow-[0_0_8px_rgba(234,179,8,0.4)]"></div>
                        <div class="w-3 h-3 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.4)]"></div>
                        <span class="ml-3 text-xs font-semibold text-gray-400 font-mono tracking-wide">server@pocketmine:~</span>
                    </div>
                    <div id="console-output" class="p-4 sm:p-6 h-[28rem] overflow-y-auto font-mono text-sm whitespace-pre-wrap flex flex-col gap-0.5 custom-scrollbar"></div>
                    <form id="cmd-form" class="flex bg-[#1a1a24] border-t border-[#2d2d3d] p-2">
                        <span class="text-green-400 font-mono px-4 py-3 font-bold">❯</span>
                        <input id="cmd-input" type="text" placeholder="'.$texts["cmdplaceholder"].'" class="flex-1 bg-transparent text-white placeholder-gray-600 font-mono focus:outline-none" autocomplete="off">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-6 py-2 rounded-lg transition mr-2 text-sm">'.$texts["send"].'</button>
                    </form>
                </div>
            </div>';
        } else {
            $mainContent = '<div class="text-center opacity-50 text-xl sm:text-2xl px-4">404 - Page not found</div>';
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>WebPanel</title><script src="https://cdn.tailwindcss.com"></script><style>@import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap\');body { font-family: \'Inter\', sans-serif; background: #0f172a; color: white; margin: 0; overflow: hidden; height: 100vh; display: flex; }.sidebar { width: 280px; background: #131c2e; border-right: 1px solid rgba(255,255,255,0.06); transition: transform 0.3s ease; z-index: 50; }@media (max-width: 768px) { .sidebar { position: fixed; height: 100%; transform: translateX(-100%); } .sidebar.open { transform: translateX(0); } }.content { flex: 1; display: flex; flex-direction: column; height: 100%; overflow-y: auto; background: radial-gradient(circle at 50% 0%, #1e293b 0%, #0f172a 100%); }.gradient-text { background: linear-gradient(135deg, #60a5fa, #c084fc); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }.user-card { background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.09); padding: 1.5rem; border-radius: 1.25rem; transition: all 0.2s; }.user-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.25); }.action-btn { text-align: center; padding: 0.7rem; border-radius: 0.75rem; font-size: 0.8125rem; font-weight: 800; text-transform: uppercase; transition: all 0.2s; border: 1px solid rgba(255,255,255,0.1); }.action-btn:hover { transform: translateY(-1px); }.btn-blue { background: rgba(59, 130, 246, 0.12); color: #60a5fa; }.btn-orange { background: rgba(249, 115, 22, 0.12); color: #fb923c; }.btn-yellow { background: rgba(234, 179, 8, 0.12); color: #facc15; }.btn-red { background: rgba(239, 68, 68, 0.12); color: #f87171; }.nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 1.2rem; border-radius: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.65); transition: all 0.2s; }.nav-item:hover { color: white; background: rgba(255,255,255,0.06); }.nav-item.active { background: rgba(96, 165, 250, 0.14); color: #60a5fa; border: 1px solid rgba(96, 165, 250, 0.18); }#player-search:focus, #cmd-input:focus { box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.25); } .custom-scrollbar::-webkit-scrollbar { width: 8px; } .custom-scrollbar::-webkit-scrollbar-track { background: #0f0f14; } .custom-scrollbar::-webkit-scrollbar-thumb { background: #2d2d3d; border-radius: 4px; } .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #4b4b63; }</style></head><body><aside id="sidebar" class="sidebar flex flex-col"><div class="p-6 p-8 flex items-center justify-between"><span class="text-2xl font-black gradient-text tracking-tighter">WebPanel</span><button onclick="toggleSidebar()" class="md:hidden text-white/50 text-2xl">×</button></div><nav class="flex-1 px-3 px-4 space-y-1.5"><a href="/" class="nav-item '.($page==="home"?"active":"").'"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg> '.$texts["dash"].'</a><a href="/players" class="nav-item '.($page==="players"?"active":"").'"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg> '.$texts["pm"].'</a><a href="/console" class="nav-item '.($page==="console"?"active":"").'"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h4M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"></path></svg> '.$texts["console"].'</a></nav></aside><div class="content"><header class="p-4 flex items-center gap-4 sticky top-0 bg-[#0f172a]/70 backdrop-blur-lg z-40 border-b border-white/5"><button onclick="toggleSidebar()" class="p-2.5 bg-white/5 hover:bg-white/10 rounded-lg md:hidden"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg></button><span class="font-bold opacity-50 uppercase text-xs tracking-widest">'.($page==="home" ? $texts["dash"] : ($page==="players" ? $texts["pm"] : $texts["console"])).'</span></header><main class="p-6 flex-1 flex flex-col items-center">'.$mainContent.'</main></div><script>function toggleSidebar(){document.getElementById("sidebar").classList.toggle("open")}</script>'.$liveScript.'</body></html>';
    }
}
