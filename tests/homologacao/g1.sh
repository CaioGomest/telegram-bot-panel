#!/bin/bash
# GUIADO 1: webhook básico, /start, leads, boas-vindas (mídia/cache), fluxo desligado, planos.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')

secao "G1.1 Webhook: segurança e entrada"
reset; setflow guiado_base
o=$($PHP /tmp/pt/hook.php "" '{}' 2>&1 | tail -1); [ "$o" = "token ausente" ] && echo "  PASS | token ausente responde 'token ausente'" || echo "  FAIL | token ausente: $o"
o=$($PHP /tmp/pt/hook.php "TOKEN-INEXISTENTE" '{}' 2>&1 | tail -1); [ "$o" = "bot não encontrado" ] && echo "  PASS | token inexistente responde 'bot não encontrado'" || echo "  FAIL | token inexistente: $o"
sql "UPDATE bots SET webhook_secret='segredo123' WHERE id=$BOTID" >/dev/null
mark; msg "/start"; chk "com segredo configurado e sem header: bloqueia (403) e não envia nada" 'count(tg("sendMessage"))==0 && stripos(json_encode($L, JSON_UNESCAPED_UNICODE), "assinatura inválida")!==false'
mark; $PHP /tmp/pt/hook.php "$TOKEN" "$($PHP /tmp/pt/upd.php msg $CHAT '/start' Maria)" "errado" >/dev/null 2>&1; chk "segredo errado: bloqueado" 'count(tg("sendMessage"))==0'
mark; $PHP /tmp/pt/hook.php "$TOKEN" "$($PHP /tmp/pt/upd.php msg $CHAT '/start' Maria)" "segredo123" >/dev/null 2>&1; chk "segredo certo: processa o /start" 'count(tg("sendMessage"))==1'
sql "UPDATE bots SET webhook_secret=NULL WHERE id=$BOTID" >/dev/null

secao "G1.2 /start cria lead e atividade"
reset
mark; msg "/start" "Maria" "maria_t"
chk "boas-vindas enviada uma vez" 'count(tg("sendMessage"))==1 && tem("Bem-vindo ao")'
chk "botão CTA com texto, callback e cor" '($b=botoes(ultimo("sendMessage"))) && count($b)==1 && $b[0]["text"]=="Quero aprender" && $b[0]["callback_data"]=="basico::ver_planos" && ($b[0]["style"]??"")=="success"'
chk "sem erro/aviso de PHP" 'semerros()'
chk "lead criado com nome e @username" 'q1("SELECT COUNT(*) FROM leads WHERE bot_id=? AND id_telegram=? AND nome=? AND nome_usuario_telegram=?",[ids()["bot"],"777000111","Maria Teste","maria_t"])==1'
chk "atividade 'Novo Lead' registrada" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND tipo=\"lead\"",[ids()["uid"]])==1'
chk "lead vinculado ao fluxo conectado" 'q1("SELECT id_fluxo FROM leads WHERE bot_id=?",[ids()["bot"]])==ids()["fluxo"]'
mark; msg "/start" "Maria" "maria_novo"
chk "2º /start repete a boas-vindas sem duplicar o lead" 'count(tg("sendMessage"))==1 && q1("SELECT COUNT(*) FROM leads WHERE bot_id=?",[ids()["bot"]])==1'
chk "2º /start atualiza o @username do lead" 'q1("SELECT nome_usuario_telegram FROM leads WHERE bot_id=?",[ids()["bot"]])=="maria_novo"'
chk "2º /start não cria atividade nova" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND tipo=\"lead\"",[ids()["uid"]])==1'
mark; msg "oi tudo bem?"
chk "texto solto (não é /start) é ignorado sem erro" 'count(tg("sendMessage"))==0 && semerros()'
mark; msg "/START"
chk "/START em maiúsculo é ignorado (só /start exato)" 'count(tg("sendMessage"))==0'
mark; msg "/start" "Sem Username" "" 888000222
chk "lead sem @username grava NULL" 'q1("SELECT nome_usuario_telegram FROM leads WHERE bot_id=? AND id_telegram=\"888000222\"",[ids()["bot"]])===null'
sql "DELETE FROM leads WHERE id_telegram='888000222'" >/dev/null

secao "G1.3 /start com campanha (rastreamento)"
reset
sql "INSERT INTO links_rastreamento (id_usuario, titulo, identificador, bot_id) VALUES ($UID_, 'camp teste', 'camp_x', $BOTID)" >/dev/null
mark; msg "/start camp_x" "Ana"
chk "/start com parâmetro mostra boas-vindas normalmente" 'count(tg("sendMessage"))==1 && tem("Bem-vindo")'
chk "origem_rastreio gravada no lead novo" 'q1("SELECT origem_rastreio FROM leads WHERE bot_id=?",[ids()["bot"]])=="camp_x"'
chk "contador do link: starts=1 e leads=1" '($r=q("SELECT starts,leads FROM links_rastreamento WHERE bot_id=? AND identificador=\"camp_x\"",[ids()["bot"]])[0]) && $r["starts"]==1 && $r["leads"]==1'
mark; msg "/start camp_x" "Ana"
chk "2º /start da mesma campanha: starts=2, leads continua 1" '($r=q("SELECT starts,leads FROM links_rastreamento WHERE bot_id=? AND identificador=\"camp_x\"",[ids()["bot"]])[0]) && $r["starts"]==2 && $r["leads"]==1'
mark; msg "/start camp_y" "Ana"
chk "origem do 1º toque é preservada (camp_x) mesmo vindo de outra campanha" 'q1("SELECT origem_rastreio FROM leads WHERE bot_id=?",[ids()["bot"]])=="camp_x"'
sql "UPDATE leads SET origem_rastreio=NULL WHERE bot_id=$BOTID" >/dev/null
mark; msg "/start camp_y" "Ana"
chk "lead antigo sem origem recebe a 1ª origem que aparecer" 'q1("SELECT origem_rastreio FROM leads WHERE bot_id=?",[ids()["bot"]])=="camp_y"'
sql "DELETE FROM links_rastreamento WHERE bot_id=$BOTID" >/dev/null

secao "G1.4 Redirecionamento (/l/slug) conta no link de origem"
reset
sql "INSERT INTO redirecionadores (id_usuario, titulo, slug) VALUES ($UID_, 'Promo', 'promo1')" >/dev/null
mark; msg "/start rd_promo1" "Beto"
chk "start rd_promo1 funciona e grava origem" 'count(tg("sendMessage"))==1 && q1("SELECT origem_rastreio FROM leads WHERE bot_id=?",[ids()["bot"]])=="rd_promo1"'
chk "redirecionador contou 1 start e 1 lead" '($r=q("SELECT starts,leads FROM redirecionadores WHERE slug=\"promo1\" AND id_usuario=?",[ids()["uid"]])) && $r && $r[0]["starts"]==1 && $r[0]["leads"]==1'
sql "DELETE FROM redirecionadores WHERE slug='promo1' AND id_usuario=$UID_" >/dev/null

secao "G1.5 Boas-vindas: textos padrão, cores e mídia"
reset
setflow guiado_base '{"boas_vindas":{"mensagem":"","texto_cta":"","cor_cta":"invalida"}}'
mark; msg "/start"
chk "mensagem vazia vira 'Bem-vindo(a)!' e CTA vazio vira 'Ver Planos'" '($e=ultimo("sendMessage")) && txt($e)=="Bem-vindo(a)!" && botoes($e)[0]["text"]=="Ver Planos"'
chk "cor inválida não gera o campo style" '!isset(botoes(ultimo("sendMessage"))[0]["style"])'
for COR in primary success danger; do
  setflow guiado_base "{\"boas_vindas\":{\"cor_cta\":\"$COR\"}}"; mark; msg "/start"
  chk "cor $COR aplicada no botão CTA" 'botoes(ultimo("sendMessage"))[0]["style"]=="'$COR'"'
done
reset
setflow guiado_base '{"boas_vindas":{"midia_tipo":"image","midia_path":"uploads/t_foto.png","mensagem":"Legenda da foto"}}'
mark; msg "/start"
chk "boas-vindas com FOTO: sendPhoto com legenda + botão + arquivo enviado" '($e=ultimo("sendPhoto")) && txt($e)=="Legenda da foto" && count(botoes($e))==1 && ($e["arquivos"]["photo"]??"")=="t_foto.png" && count(tg("sendMessage"))==0'
setflow guiado_base '{"boas_vindas":{"midia_tipo":"video","midia_path":"uploads/t_video.mp4","mensagem":"Legenda do vídeo"}}'
mark; msg "/start"
chk "boas-vindas com VÍDEO: sendVideo com legenda + botão" '($e=ultimo("sendVideo")) && txt($e)=="Legenda do vídeo" && count(botoes($e))==1 && ($e["arquivos"]["video"]??"")=="t_video.mp4"'
setflow guiado_base '{"boas_vindas":{"midia_tipo":"image","midia_path":"config.php","mensagem":"X"}}'
mark; msg "/start"
chk "mídia com caminho fora de uploads/ é recusada: cai pra texto simples" 'count(tg("sendPhoto"))==0 && count(tg("sendMessage"))==1 && tem("X")'
setflow guiado_base '{"boas_vindas":{"midia_tipo":"image","midia_path":"uploads/../config.php","mensagem":"Y"}}'
mark; msg "/start"
chk "path traversal (uploads/../config.php) é recusado" 'count(tg("sendPhoto"))==0 && count(tg("sendMessage"))==1'
setflow guiado_base '{"boas_vindas":{"midia_tipo":"image","midia_path":"uploads/nao_existe.png","mensagem":"Z"}}'
mark; msg "/start"
chk "mídia que não existe mais no servidor: cai pra texto simples" 'count(tg("sendPhoto"))==0 && count(tg("sendMessage"))==1'
LONGA=$(php -r 'echo str_repeat("Linha de texto longa. ", 60);')
setflow guiado_base "{\"boas_vindas\":{\"midia_tipo\":\"image\",\"midia_path\":\"uploads/t_foto.png\",\"mensagem\":\"$LONGA\"}}"
mark; msg "/start"
chk "boas-vindas com FOTO e texto > 1024 chars (limite de legenda): o lead ainda recebe algo" 'count(tgok("sendMessage"))+count(tgok("sendPhoto"))>=1'

reset; setflow guiado_base '{"boas_vindas":{"midia_tipo":"image","midia_path":"uploads/t_foto.png","mensagem":"Texto que não pode se perder"}}'
falha '{"sendPhoto":"Bad Request: PHOTO_INVALID_DIMENSIONS"}'
mark; msg "/start"
chk "Telegram recusa a foto de boas-vindas (dimensões/tamanho): o lead recebe o texto com o botão do mesmo jeito" 'count(tgerr())==1 && tem("Texto que não pode se perder") && cbs(ultimo("sendMessage"))==["basico::ver_planos"]'
falha '{"sendVideo":"Bad Request: wrong file"}'
setflow guiado_base '{"boas_vindas":{"midia_tipo":"video","midia_path":"uploads/t_video.mp4","mensagem":"Texto do vídeo"}}'
mark; msg "/start"
chk "idem com vídeo recusado" 'tem("Texto do vídeo") && cbs(ultimo("sendMessage"))==["basico::ver_planos"]'
semfalha

secao "G1.6 Boas-vindas: cache de mídia (file_id)"
reset
setflow guiado_base '{"cache_chat_id":"-1001000000099","boas_vindas":{"midia_tipo":"image","midia_path":"uploads/t_foto.png","mensagem":"Com cache"}}'
mark; msg "/start" "Ana" "" 777000111
chk "1º envio: sobe a foto pro grupo de cache e manda pro lead por file_id" '($f=tg("sendPhoto")) && count($f)==2 && $f[0]["p"]["chat_id"]=="-1001000000099" && isset($f[0]["arquivos"]) && $f[1]["p"]["chat_id"]=="777000111" && strpos($f[1]["p"]["photo"],"SIMFID")===0 && !isset($f[1]["arquivos"])'
mark; msg "/start" "Ana"
chk "2º envio: usa só o file_id guardado (sem novo upload pro cache)" '($f=tg("sendPhoto")) && count($f)==1 && $f[0]["p"]["chat_id"]=="777000111" && strpos($f[0]["p"]["photo"],"SIMFID")===0'
chk "mapa de cache gravado em storage/cache_midia.json" 'count(json_decode(file_get_contents("/tmp/pt/app/storage/cache_midia.json"),true))==1'
touch -d "+1 minute" /tmp/pt/app/uploads/t_foto.png
mark; msg "/start" "Ana"
chk "mídia trocada (data de modificação nova) gera file_id novo" 'count(tg("sendPhoto"))==2'

secao "G1.7 Fluxo desligado e dados corrompidos"
reset
setflow guiado_base '{"ativo":false}'
mark; msg "/start"
chk "fluxo desligado: /start responde 'temporariamente indisponível'" 'count(tg("sendMessage"))==1 && tem("temporariamente indispon")'
mark; cb "basico::ver_planos"
chk "fluxo desligado: botão de planos também responde indisponível" 'count(tg("sendMessage"))==1 && tem("temporariamente indispon")'
mark; msg "qualquer coisa"
chk "fluxo desligado: texto solto é ignorado" 'count(tg("sendMessage"))==0'
sql "UPDATE fluxos SET dados_fluxograma='nao-e-json' WHERE id=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["fluxo"];')" >/dev/null
mark; msg "/start"
chk "dados do fluxo corrompidos: sem fatal; manda boas-vindas padrão" 'semerros() && count(tg("sendMessage"))<=1'
sql "UPDATE bots SET id_fluxo_conectado=NULL WHERE id=$BOTID" >/dev/null
mark; msg "/start"
chk "bot sem fluxo conectado: só cria o lead, sem enviar nada e sem erro" 'count(tg("sendMessage"))==0 && semerros() && q1("SELECT COUNT(*) FROM leads WHERE bot_id=?",[ids()["bot"]])==1'

secao "G1.8 Lista de planos"
reset; setflow guiado_base
mark; msg "/start"; cb "basico::ver_planos"
chk "lista com 4 planos, preço formatado e callback por id" '($e=ultimo("sendMessage")) && txt($e)=="Escolha um plano:" && ($b=botoes($e)) && $b[0]["text"]=="Mensal — R$ 19,90" && $b[0]["callback_data"]=="basico::plano::p_mensal" && $b[1]["text"]=="Anual — R$ 149,00"'
chk "cor por plano (primary/success) e nenhuma cor nos sem cor" '($b=botoes(ultimo("sendMessage"))) && $b[0]["style"]=="primary" && $b[1]["style"]=="success" && !isset($b[2]["style"])'
chk "botão de suporte é URL t.me com a cor configurada" '($b=botoes(ultimo("sendMessage"))) && ($s=end($b)) && $s["url"]=="https://t.me/suportehomolog" && $s["style"]=="danger"'
chk "sem Prévias/Packs ativos, não aparecem esses botões" '!in_array("basico::previa", cbs(ultimo("sendMessage"))) && !in_array("basico::packs", cbs(ultimo("sendMessage")))'
chk "todos os callback_data cabem no limite do Telegram (a validação do simulador já recusaria)" 'count(tgerr())==0'
for S in "suportehomolog" "https://t.me/abc_def" "t.me/abcd" "telegram.me/abcd"; do
  setflow guiado_base "{\"suporte\":\"$S\"}"; mark; cb "basico::ver_planos"
  chk "suporte '$S' vira botão de URL" '($b=botoes(ultimo("sendMessage"))) && isset(end($b)["url"]) && strpos(end($b)["url"],"https://t.me/")===0'
done
for S in "ab" "@com espaco" "http://evil.com/x" ""; do
  setflow guiado_base "{\"suporte\":\"$S\"}"; mark; cb "basico::ver_planos"
  chk "suporte inválido '$S' não gera botão de URL" 'count(array_filter(botoes(ultimo("sendMessage")), fn($b)=>isset($b["url"])))==0'
done
setflow guiado_base '{"planos!":[]}'
mark; cb "basico::ver_planos"
chk "sem planos: 'Nenhum plano disponível no momento.'" 'count(tg("sendMessage"))==1 && tem("Nenhum plano dispon")'
setflow guiado_base '{"previas":{"ativo":true},"packs":{"ativo":true}}'
mark; cb "basico::ver_planos"
chk "Prévias+Packs ativos: aparecem os botões com os textos configurados" '($b=botoes(ultimo("sendMessage"))) && in_array("basico::previa",cbs(ultimo("sendMessage"))) && in_array("basico::packs",cbs(ultimo("sendMessage")))'
chk "textos dos botões de Prévias e Packs" '($t=array_column(botoes(ultimo("sendMessage")),"text")) && in_array("🎬 Aula grátis",$t) && in_array("📦 Aulas avulsas",$t)'
setflow guiado_base '{"previas":{"ativo":true,"midias!":[{"tipo":"image","path":"uploads/nao_existe.png"}]},"packs":{"ativo":true,"itens!":[{"id":"x","ativo":false,"nome":"Off","preco":5}]}}'
mark; cb "basico::ver_planos"
chk "Prévias sem mídia existente e Packs sem item ativo: botões NÃO aparecem" '!in_array("basico::previa",cbs(ultimo("sendMessage"))) && !in_array("basico::packs",cbs(ultimo("sendMessage")))'
mark; cb "basico::plano::nao_existe"
chk "plano inexistente: 'Esse plano não existe mais'" 'tem("não existe mais")'
secao "G1.9 /start simultâneos e botão Recomeçar"
reset; setflow guiado_base
mark; ( msg "/start" "Maria" "maria_t" ) & ( msg "/start" "Maria" "maria_t" ) & ( msg "/start" "Maria" "maria_t" ) & ( msg "/start" "Maria" "maria_t" ) & wait
chk "4 /start ao mesmo tempo de um cliente novo: nenhum erro e UM lead só (sem duplicar contagem)" 'semerros() && q1("SELECT COUNT(*) FROM leads WHERE bot_id=? AND id_telegram=\"777000111\"",[ids()["bot"]])==1'
mark; cb "/start"
chk "botão 'Recomeçar' (callback /start dos avisos): reinicia o fluxo com a boas-vindas, sem novo lead" 'count(tg("sendMessage"))==1 && tem("Bem-vindo") && q1("SELECT COUNT(*) FROM leads WHERE bot_id=?",[ids()["bot"]])==1'
echo; echo "FIM G1"
