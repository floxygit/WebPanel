<?php

declare(strict_types=1);

namespace floxygit\WebPanel;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;

class Main extends PluginBase {
    private $socket;
    private Config $panelData;

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->panelData = new Config($this->getDataFolder() . "panelData.json", Config::JSON, ["lang" => "en", "name" => "WebPanel", "setup" => false]);
        
        $port = (int)$this->getConfig()->get("appPort", 7800);
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        @socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (@socket_bind($this->socket, "0.0.0.0", $port) && @socket_listen($this->socket)) {
            socket_set_nonblock($this->socket);
            $this->getScheduler()->scheduleRepeatingTask(new class($this->socket, $this->panelData) extends Task {
                public function __construct(private $s, private Config $data) {}

                public function onRun(): void {
                    if (($client = @socket_accept($this->s)) !== false) {
                        $buf = socket_read($client, 2048);
                        
                        if (preg_match('/setlang=(\w+)&setname=([^&\s]+)/', $buf, $m)) {
                            $this->data->set("lang", $m[1]);
                            $this->data->set("name", urldecode($m[2]));
                            $this->data->set("setup", true);
                            $this->data->save();
                        }

                        $html = $this->render();
                        $res = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n" . $html;
                        @socket_write($client, $res, strlen($res));
                        @socket_close($client);
                    }
                }

                private function render(): string {
                    if ($this->data->get("setup")) {
                        return "<body style='background:#1a1a2e;color:white;display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif;'><h1>" . $this->data->get("name") . " - Coming Soon...</h1></body>";
                    }
                    
                    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>WebPanel | Welcome</title><script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script><style>
                        @import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap\');
                        html, body { width: 100%; height: 100%; }
                        body { font-family: \'Inter\', sans-serif; background: radial-gradient(circle at top left, #1a1a2e, #16213e, #0f3460); display: flex; align-items: center; justify-content: center; margin: 0; overflow: hidden; color: white; }
                        .glass-card { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 24px; padding: 3rem; width: 90%; max-width: 400px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); text-align: center; }
                        .card-animate { animation: fadeIn 0.6s ease-out forwards; }
                        .card-exit { animation: fadeOut 0.4s ease-in forwards; }
                        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
                        @keyframes fadeOut { from { opacity: 1; transform: translateY(0); } to { opacity: 0; transform: translateY(-20px); } }
                        .gradient-text { background: linear-gradient(135deg, #60a5fa, #c084fc); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 800; }
                        .next-btn { background: linear-gradient(135deg, #60a5fa, #c084fc); border: none; border-radius: 14px; color: white; font-weight: 600; padding: 0.85rem 2.5rem; cursor: pointer; transition: 0.2s; width: 100%; }
                        .panel-input { width: 100%; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 14px; color: white; padding: 1rem; outline: none; box-sizing: border-box; }
                        .great-name-overlay { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; z-index: 100; pointer-events: none; }
                        .great-name-text { font-weight: 800; font-size: clamp(2rem, 8vw, 4rem); background: linear-gradient(135deg, #60a5fa, #c084fc); -webkit-background-clip: text; -webkit-text-fill-color: transparent; opacity: 0; animation: greatNamePop 1.8s ease-out forwards; }
                        @keyframes greatNamePop { 0% { opacity: 0; transform: scale(0.6); } 30% { opacity: 1; transform: scale(1.08); } 100% { opacity: 0; transform: scale(1.1); } }
                    </style></head><body>
                        <div class="glass-card card-animate" id="step1">
                            <h1 class="text-4xl mb-8 gradient-text">WebPanel</h1>
                            <select id="language" class="w-full bg-white/10 border border-white/20 text-white rounded-xl p-4 mb-6 outline-none"><option value="en" class="bg-[#1a1a2e]">English</option><option value="de" class="bg-[#1a1a2e]">Deutsch</option></select>
                            <button class="next-btn" onclick="goToStep2()">Next →</button>
                        </div>
                        <div class="glass-card" id="step2" style="display:none;">
                            <h1 class="text-4xl mb-2 gradient-text">WebPanel</h1>
                            <p class="text-white/40 text-sm mb-8">How do you want to call the Panel?</p>
                            <input class="panel-input" type="text" id="panelName" maxlength="20" placeholder="My Panel" autocomplete="off">
                            <button class="next-btn mt-6" onclick="confirmName()">Confirm</button>
                        </div>
                        <div class="great-name-overlay" id="greatOverlay" style="display:none;"><div class="great-name-text">Great Name!</div></div>
                        <script>
                            function goToStep2(){ 
                                document.getElementById("step1").classList.add("card-exit");
                                setTimeout(() => { document.getElementById("step1").style.display="none"; let s2=document.getElementById("step2"); s2.style.display="block"; s2.classList.add("card-animate"); }, 400);
                            }
                            function confirmName(){
                                let name = document.getElementById("panelName").value.trim();
                                let lang = document.getElementById("language").value;
                                if(!name) return;
                                document.getElementById("step2").classList.add("card-exit");
                                setTimeout(() => {
                                    document.getElementById("step2").style.display="none";
                                    document.getElementById("greatOverlay").style.display="flex";
                                    setTimeout(() => { window.location.href = "/?setlang=" + lang + "&setname=" + encodeURIComponent(name); }, 1800);
                                }, 400);
                            }
                        </script></body></html>';
                }
            }, 1);
        }
    }

    protected function onDisable(): void { if($this->socket) @socket_close($this->socket); }
}
