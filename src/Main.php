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
                        if (preg_match('/setlang=(\w+)/', $buf, $m)) { $this->data->set("lang", $m[1]); $this->data->save(); }
                        if (preg_match('/setname=([^&\s]+)/', $buf, $m)) { $this->data->set("name", urldecode($m[1])); $this->data->set("setup", true); $this->data->save(); }

                        $html = $this->render();
                        $res = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n" . $html;
                        @socket_write($client, $res, strlen($res));
                        @socket_close($client);
                    }
                }

                private function render(): string {
                    $lang = $this->data->get("lang");
                    if ($this->data->get("setup")) return "<body style='background:#1a1a2e;color:white;display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif;'><h1>Coming Soon...</h1></body>";
                    
                    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>WebPanel</title><script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script><style>
                        body{font-family:"Inter",sans-serif;background:radial-gradient(circle at top left,#1a1a2e,#16213e,#0f3460);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;overflow:hidden;color:white;}
                        .glass-card{background:rgba(255,255,255,0.05);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.1);border-radius:24px;padding:2.5rem;width:90%;max-width:380px;text-align:center;animation:fadeIn 0.6s ease-out;}
                        @keyframes fadeIn{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
                        .gradient-text{background:linear-gradient(135deg,#60a5fa,#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;font-weight:800;}
                        .next-btn{background:linear-gradient(135deg,#60a5fa,#c084fc);border-radius:14px;padding:0.8rem;width:100%;font-weight:600;margin-top:1.5rem;transition:0.2s;}
                        .panel-input{width:100%;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:14px;color:white;padding:0.8rem;outline:none;}
                    </style></head><body>
                    <div class="glass-card" id="s1">
                        <h1 class="text-4xl mb-6 gradient-text">WebPanel</h1>
                        <select id="l" class="w-full bg-white/10 border border-white/20 p-3 rounded-xl outline-none"><option value="en">English</option><option value="de">Deutsch</option></select>
                        <button class="next-btn" onclick="document.getElementById(\'s1\').style.display=\'none\';document.getElementById(\'s2\').style.display=\'block\'">Next →</button>
                    </div>
                    <div class="glass-card" id="s2" style="display:none;">
                        <h1 class="text-4xl mb-4 gradient-text">WebPanel</h1>
                        <input class="panel-input" type="text" id="n" maxlength="20" placeholder="My Panel">
                        <button class="next-btn" onclick="location.href=\'/?setlang=\'+document.getElementById(\'l\').value+\'&setname=\'+document.getElementById(\'n\').value">Confirm</button>
                    </div></body></html>';
                }
            }, 1);
        }
    }

    protected function onDisable(): void { if($this->socket) @socket_close($this->socket); }
}
