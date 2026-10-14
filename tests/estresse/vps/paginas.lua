-- Script do wrk: usuários navegando no painel (cada conexão = 1 vendedor logado).
--   WRK_COOKIES=/root/res/cookies_usuarios.txt  WRK_THINK=0       (sem pausa: mede o máximo)
--   WRK_THINK=15000                                                (pausa média de 15 s entre as páginas: "humanos")
--   wrk -t2 -c200 -d60s --latency -s paginas.lua http://127.0.0.1:8080
-- Mistura: 1/3 das requisições é a consulta de notificações (o navegador pergunta a cada 30 s com o painel aberto); o resto são páginas.
local cookies = {}
local threads = {}
local think = tonumber(os.getenv("WRK_THINK") or "0")
local arquivo = os.getenv("WRK_COOKIES") or "/root/res/cookies_usuarios.txt"
local so_caminho = os.getenv("WRK_PATH")   -- se definido, só essa URL (para medir página por página)

local paginas = {
  {20, "/index"}, {8, "/index?periodo=30"}, {12, "/vendas"}, {4, "/vendas?aba=pagas&periodo=30"}, {7, "/leads"}, {3, "/leads?pagina=2"},
  {4, "/leads?status=pagou"}, {7, "/bots"}, {6, "/fluxos"}, {4, "/remarketing"}, {3, "/traqueamento"}, {3, "/links_rastreamento"},
  {3, "/gateways"}, {2, "/configuracao_usuario"}, {4, "/ranking"}, {2, "/comunidade"}, {2, "/redirecionamento"}, {2, "/webhooks"}, {1, "/termos"},
}
local total = 0
for _, p in ipairs(paginas) do total = total + p[1] end

function setup(thread)
  table.insert(threads, thread)
end

function init(args)
  math.randomseed(os.time() + (tonumber(tostring({}):match("0x(%x+)"), 16) or 0))
  for linha in io.lines(arquivo) do
    if #linha > 10 then table.insert(cookies, linha) end
  end
  status_contagem = {}
  rota_erro = {}
end

function request()
  local cookie = cookies[math.random(#cookies)]
  local caminho
  if so_caminho and so_caminho ~= "" then
    caminho = so_caminho
  elseif math.random() < 0.333 then
    caminho = "/ajax/notificacoes.php"
  else
    local r = math.random(total)
    for _, p in ipairs(paginas) do
      r = r - p[1]
      if r <= 0 then caminho = p[2]; break end
    end
  end
  ultimo = caminho
  return wrk.format("GET", caminho, {["Cookie"] = "PHPSESSID=" .. cookie, ["Accept"] = "text/html,application/json"})
end

function delay()
  if think <= 0 then return 0 end
  -- pausa exponencial com a média pedida (usuário lendo a tela)
  return math.floor(-math.log(1 - math.random()) * think)
end

function response(status, headers, body)
  status_contagem[status] = (status_contagem[status] or 0) + 1
  if status ~= 200 then
    rota_erro[ultimo] = (rota_erro[ultimo] or 0) + 1
  end
end

function done(summary, latency, requests)
  io.write("--- resposta por código (todas as threads)\n")
  local tot = {}
  for _, t in ipairs(threads) do
    local sc = t:get("status_contagem")
    if sc then for k, v in pairs(sc) do tot[k] = (tot[k] or 0) + v end end
  end
  for k, v in pairs(tot) do io.write(string.format("  HTTP %s: %d\n", tostring(k), v)) end
  io.write(string.format("--- latência: p50=%.1fms p90=%.1fms p99=%.1fms max=%.1fms\n", latency:percentile(50) / 1000, latency:percentile(90) / 1000, latency:percentile(99) / 1000, latency.max / 1000))
end
