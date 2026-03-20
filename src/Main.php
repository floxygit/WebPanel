<?php

declare(strict_types=1);

namespace floxygit\WebPanel;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\player\Player;

class Main extends PluginBase
{
    private $socket;
    private $appPassword;

    protected function onEnable(): void
    {
        $config = $this->getConfig();
        if (!$config->exists("appPassword")) {
            $config->set("appPassword", "1234");
            $config->save();
        }
        $this->appPassword = (string)$config->get("appPassword", "1234");

        $port = (int)$this->getConfig()->get("appPort", 7800);
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        @socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (@socket_bind($this->socket, "0.0.0.0", $port) && @socket_listen($this->socket)) {
            socket_set_nonblock($this->socket);
            $this->getScheduler()->scheduleRepeatingTask(new class($this->socket, $this, $this->appPassword) extends Task {
                public function __construct(private $s, private Main $plugin, private $password) {}

                public function onRun(): void
                {
                    if (($client = @socket_accept($this->s)) === false) {
                        return;
                    }

                    $buf = @socket_read($client, 4096);
                    if (!$buf) {
                        @socket_close($client);
                        return;
                    }

                    if ($this->password !== "") {
                        $authorized = false;
                        if (preg_match('/^Authorization:\s*Basic\s+([^\r\n]+)/im', $buf, $m)) {
                            $decoded = base64_decode(trim($m[1]));
                            if (strpos($decoded, ':') !== false) {
                                $pass = explode(':', $decoded, 2)[1];
                                if ($pass === $this->password) {
                                    $authorized = true;
                                }
                            }
                        }
                        if (!$authorized) {
                            $this->sendResponse($client, "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Basic realm=\"WebPanel\"\r\nContent-Type: text/html\r\n\r\n<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>WebPanel</title><style>body{background:#0f172a;color:white;font-family:Inter,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}</style></head><body><div style=\"text-align:center\"><h1>Passwort erforderlich</h1><p>Username: beliebig<br>Password: " . htmlspecialchars($this->password) . "</p></div></body></html>");
                            @socket_close($client);
                            return;
                        }
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

                    $html = $this->render($buf);
                    $this->sendResponse($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n" . $html);
                    @socket_close($client);
                }

                private function sendResponse($client, string $content): void
                {
                    @socket_write($client, $content, strlen($content));
                }

                private function render(string $buffer): string
                {
                    $page = "home";
                    if (preg_match('#^GET /players\s#', $buffer)) {
                        $page = "players";
                    }

                    $texts = [
                        "welcome" => "Welcome!",
                        "sub"     => "Your dashboard is ready for action.",
                        "pm"      => "Player Management",
                        "dash"    => "Dashboard",
                        "search"  => "Search players...",
                    ];

                    $liveScript = $page === "players" ? '
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
                            playersContainer.innerHTML = html;
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
                    </script>' : '';

                    if ($page === "home") {
                        $mainContent = '<div class="text-center px-4"><h1 class="text-5xl sm:text-7xl md:text-8xl font-black mb-6 gradient-text tracking-tighter">'.$texts["welcome"].'</h1><p class="text-white/50 text-base sm:text-lg">'.$texts["sub"].'</p></div>';
                    } elseif ($page === "players") {
                        $mainContent = '<div class="w-full max-w-7xl px-4 sm:px-6 lg:px-8"><div class="flex flex-col gap-6 mb-10"><div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5"><h2 class="text-2xl sm:text-3xl font-black tracking-tight">'.$texts["pm"].'</h2><div id="online-count" class="px-4 py-1.5 rounded-full bg-blue-500/10 text-blue-400 text-xs sm:text-sm font-bold border border-blue-500/20 whitespace-nowrap text-center">0 online</div></div><input id="player-search" type="text" placeholder="'.$texts["search"].'" class="w-full sm:w-72 bg-[#1f2937] border border-[#4b5563] text-white placeholder:text-[#9ca3af] rounded-2xl px-6 py-4 focus:outline-none focus:border-[#60a5fa] focus:bg-[#334155] transition-all"></div><div id="players-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 sm:gap-6"><div class="col-span-full text-center py-16 opacity-30 italic">Loading players...</div></div></div>';
                    } else {
                        $mainContent = '<div class="text-center opacity-50 text-xl sm:text-2xl px-4">404 - Page not found</div>';
                    }

                    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>WebPanel</title><script src="https://cdn.tailwindcss.com"></script><style>@import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap\');body { font-family: \'Inter\', sans-serif; background: #0f172a; color: white; margin: 0; overflow: hidden; height: 100vh; display: flex; }.sidebar { width: 280px; background: #131c2e; border-right: 1px solid rgba(255,255,255,0.06); transition: transform 0.3s ease; z-index: 50; }@media (max-width: 768px) { .sidebar { position: fixed; height: 100%; transform: translateX(-100%); } .sidebar.open { transform: translateX(0); } }.content { flex: 1; display: flex; flex-direction: column; height: 100%; overflow-y: auto; background: radial-gradient(circle at 50% 0%, #1e293b 0%, #0f172a 100%); }.gradient-text { background: linear-gradient(135deg, #60a5fa, #c084fc); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }.user-card { background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.09); padding: 1.5rem; border-radius: 1.25rem; transition: all 0.2s; }.user-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.25); }.action-btn { text-align: center; padding: 0.7rem; border-radius: 0.75rem; font-size: 0.8125rem; font-weight: 800; text-transform: uppercase; transition: all 0.2s; border: 1px solid rgba(255,255,255,0.1); }.action-btn:hover { transform: translateY(-1px); }.btn-blue { background: rgba(59, 130, 246, 0.12); color: #60a5fa; }.btn-orange { background: rgba(249, 115, 22, 0.12); color: #fb923c; }.btn-yellow { background: rgba(234, 179, 8, 0.12); color: #facc15; }.btn-red { background: rgba(239, 68, 68, 0.12); color: #f87171; }.nav-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 1.2rem; border-radius: 0.75rem; font-weight: 700; color: rgba(255,255,255,0.65); transition: all 0.2s; }.nav-item:hover { color: white; background: rgba(255,255,255,0.06); }.nav-item.active { background: rgba(96, 165, 250, 0.14); color: #60a5fa; border: 1px solid rgba(96, 165, 250, 0.18); }#player-search:focus { box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.25); }</style></head><body><aside id="sidebar" class="sidebar flex flex-col"><div class="p-6 sm:p-8 flex items-center justify-between"><span class="text-2xl font-black gradient-text tracking-tighter">WebPanel</span><button onclick="toggleSidebar()" class="md:hidden text-white/50 text-2xl">×</button></div><nav class="flex-1 px-3 sm:px-4 space-y-1.5"><a href="/" class="nav-item '.($page==="home"?"active":"").'"><svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg> '.$texts["dash"].'</a><a href="/players" class="nav-item '.($page==="players"?"active":"").'"><svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg> '.$texts["pm"].'</a></nav></aside><div class="content"><header class="p-4 sm:p-6 md:p-8 flex items-center gap-4 sticky top-0 bg-[#0f172a]/70 backdrop-blur-lg z-40 border-b border-white/5"><button onclick="toggleSidebar()" class="p-2.5 sm:p-3 bg-white/5 hover:bg-white/10 rounded-lg md:hidden"><svg class="w-6 h-6 sm:w-7 sm:h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg></button><span class="font-bold opacity-50 uppercase text-xs sm:text-sm tracking-widest">'.($page==="home" ? $texts["dash"] : $texts["pm"]).'</span></header><main class="p-6 sm:p-8 md:p-10 lg:p-12 flex-1 flex flex-col items-center">'.$mainContent.'</main></div><script>function toggleSidebar(){document.getElementById("sidebar").classList.toggle("open")}</script>'.$liveScript.'</body></html>';
                }
            }, 1);
        }
    }

    protected function onDisable(): void
    {
        if ($this->socket) {
            @socket_close($this->socket);
        }
    }
}
