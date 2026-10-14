#!/bin/bash
# VISUAL 1: blocos simples (mensagem, imagem, documento, vídeo, áudio, link, grupo), botões, casos estranhos.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
AGEND='q("SELECT * FROM mensagens_agendadas WHERE bot_id=? ORDER BY id",[ids()["bot"]])'

secao "V1.1 Sequência de blocos de conteúdo"
reset; setflow v_sequencia
mark; msg "/start" "Maria"
chk "ordem de envio: msg, foto, documento, foto, vídeo, voz, link, convite+msg do grupo, msg final" 'sequencia()==["sendMessage","sendPhoto","sendDocument","sendPhoto","sendVideo","sendVoice","sendMessage","createChatInviteLink","sendMessage","sendMessage"]'
chk "mensagem simples" 'txt(tg("sendMessage")[0])=="Olá! Mensagem simples ✅"'
chk "FOTO com spoiler + legenda" '($e=tg("sendPhoto")[0]) && txt($e)=="Foto com legenda" && ($e["p"]["has_spoiler"]??false)==true && $e["arquivos"]["photo"]=="t_foto.png"'
chk "imagem como DOCUMENTO (sendDocument) com legenda, sem spoiler" '($e=tg("sendDocument")[0]) && txt($e)=="Imagem como documento" && $e["arquivos"]["document"]=="t_foto.jpg" && !isset($e["p"]["has_spoiler"])'
chk "foto sem legenda: sem campo caption e sem spoiler" '($e=tg("sendPhoto")[1]) && !isset($e["p"]["caption"]) && !isset($e["p"]["has_spoiler"])'
chk "VÍDEO com spoiler + legenda" '($e=tg("sendVideo")[0]) && txt($e)=="Vídeo" && ($e["p"]["has_spoiler"]??false)==true && $e["arquivos"]["video"]=="t_video.mp4"'
chk "ÁUDIO enviado como nota de voz (sendVoice) com legenda" '($e=tg("sendVoice")[0]) && txt($e)=="Áudio" && $e["arquivos"]["voice"]=="t_audio.mp3" && count(tg("sendAudio"))==0'
chk "LINK: botão URL com o texto configurado" '($e=tg("sendMessage")[1]) && txt($e)=="Clique abaixo:" && botoes($e)[0]["url"]=="https://exemplo.com/oferta" && botoes($e)[0]["text"]=="Abrir oferta"'
chk "GRUPO: convite de 1 uso/15 min e botão com o link e o texto configurado" '($c=tg("createChatInviteLink")[0]) && $c["p"]["chat_id"]=="-1001000000001" && $c["p"]["member_limit"]==1 && ($e=tg("sendMessage")[2]) && botoes($e)[0]["text"]=="Entrar no VIP" && strpos(botoes($e)[0]["url"],"https://t.me/+SIM")===0'
chk "auto-delete: foto(40s), vídeo(60s) e áudio(90s) entram na fila; documento e foto sem auto-delete não" '($a='"$AGEND"') && count($a)==3 && abs(strtotime($a[0]["apagar_em"])-time()-40)<5 && abs(strtotime($a[1]["apagar_em"])-time()-60)<5 && abs(strtotime($a[2]["apagar_em"])-time()-90)<5'
chk "ids agendados são os das mensagens enviadas (foto, vídeo, voz)" '($a='"$AGEND"') && (int)$a[0]["message_id"]==tg("sendPhoto")[0]["mid"] && (int)$a[1]["message_id"]==tg("sendVideo")[0]["mid"] && (int)$a[2]["message_id"]==tg("sendVoice")[0]["mid"]'
chk "nenhum erro do Telegram nem aviso de PHP" 'count(tgerr())==0 && semerros()'
sql "UPDATE mensagens_agendadas SET apagar_em = NOW() - INTERVAL 1 SECOND WHERE bot_id=$BOTID" >/dev/null
mark; cron cron_aviso_vencimento
chk "cron apaga as 3 mensagens vencidas" 'count(tg("deleteMessage"))==3'

secao "V1.2 Falhas de envio de mídia"
reset; setflow v_sequencia
falha '{"sendVoice":"Bad Request: wrong file type for voice"}'
mark; msg "/start"
chk "voz recusada pelo Telegram: tenta como áudio normal (sendAudio) e o fluxo segue até o fim" 'count(tgok("sendAudio"))==1 && count(tg("sendVoice"))==1 && tem("Fim da sequência")'
falha '{"sendVoice":"x","sendAudio":"y"}'
mark; msg "/start"
chk "voz e áudio falham: nada trava, o fluxo continua (link, grupo, fim)" 'tem("Fim da sequência") && tem("Clique abaixo")'
falha '{"sendPhoto":"Bad Request: wrong file"}'
mark; msg "/start"
chk "foto falha: demais blocos continuam" 'tem("Fim da sequência") && count(tgok("sendVideo"))==1'
semfalha
setflow v_sequencia '{"operators":{"foto":{"properties":{"image_path":"uploads/nao_existe.png"}},"vid":{"properties":{"video_path":"config.php"}},"aud":{"properties":{"audio_path":"uploads/../config.php"}},"doc":{"properties":{"image_path":""}}}}'
mark; msg "/start"
chk "mídia inexistente, fora de uploads/, traversal ou vazia: blocos são pulados em silêncio e o resto roda" 'count(tg("sendPhoto"))==1 && count(tg("sendVideo"))==0 && count(tg("sendVoice"))==0 && count(tg("sendDocument"))==0 && tem("Fim da sequência") && semerros()'
reset; setflow v_sequencia '{"operators":{"foto":{"properties":{"auto_delete":false}},"vid":{"properties":{"auto_delete_seconds":0}},"aud":{"properties":{"auto_delete_seconds":9999999}}}}'
mark; msg "/start"
chk "auto-delete desligado ou com 0 s não agenda; valor enorme é limitado a 48h" '($a='"$AGEND"') && count($a)==1 && (strtotime($a[0]["apagar_em"])-time())<=172800+5'

secao "V1.3 Textos longos"
reset
LONGO=$(php -r 'echo str_repeat("Parágrafo de texto bem longo. ", 200);')
setflow v_sequencia "{\"operators\":{\"m1\":{\"properties\":{\"conteudo\":\"$LONGO\"}},\"foto\":{\"properties\":{\"caption\":\"$LONGO\"}}}}"
mark; msg "/start"
chk "mensagem de texto > 4096 caracteres: o cliente ainda recebe a mensagem (cortada)" 'count(tgok("sendMessage"))>=2 && mb_strlen(txt(tg("sendMessage")[0]))<=4096'
chk "legenda de foto > 1024 caracteres: o cliente ainda recebe a foto" 'count(tgok("sendPhoto"))>=1'
chk "o texto da legenda longa não se perde (vai numa mensagem logo depois)" 'count(array_filter(tg("sendMessage"),fn($e)=>strpos(txt($e),"Parágrafo de texto bem longo")!==false))>=2'

secao "V1.4 Botões do Editor Visual"
reset; setflow v_botoes
mark; msg "/start"
chk "teclado com 3 botões (2 + 1), callback btn::<bloco>::<índice>, cores por botão" '($e=ultimo("sendMessage")) && txt($e)=="Escolha:" && cbs($e)==["btn::b::0","btn::b::1","btn::b::2"] && count($e["p"]["reply_markup"]["inline_keyboard"])==2 && ($b=botoes($e)) && $b[0]["style"]=="success" && $b[1]["style"]=="danger" && !isset($b[2]["style"]) && $b[2]["text"]=="Opção C legado"'
mark; cb "btn::b::0" 777
chk "clique no botão A: apaga a mensagem dos botões (sumir após clique) e segue a saída 0" '($d=tg("deleteMessage")) && count($d)==1 && $d[0]["p"]["message_id"]==777 && tem("Você escolheu A")'
mark; cb "btn::b::1" 778
chk "botão B -> saída 1" 'tem("Você escolheu B") && !tem("Você escolheu A")'
mark; cb "btn::b::2" 779
chk "botão C (formato legado em string) -> saída 2" 'tem("Você escolheu C")'
mark; cb "btn::b::9"
chk "índice de botão sem ligação: nada acontece (sem erro)" 'count(tg("sendMessage"))==0 && semerros()'
mark; cb "btn::naoexiste::0"
chk "bloco inexistente: nada acontece (sem erro)" 'count(tg("sendMessage"))==0 && semerros()'
mark; cb "btn::"
chk "callback malformado: ignorado" 'count(tg("sendMessage"))==0 && semerros()'
mark; msg "Opção B"
chk "cliente digita o texto do botão (callbacks antigos): segue a saída certa" 'tem("Você escolheu B")'
mark; msg "texto que não é botão"
chk "texto qualquer que não é botão: ignorado" 'count(tg("sendMessage"))==0'
setflow v_botoes '{"operators":{"b":{"properties":{"sumir_apos_clique":false}}}}'
mark; cb "btn::b::0" 777
chk "sem 'sumir após o clique': não apaga a mensagem" 'count(tg("deleteMessage"))==0 && tem("Você escolheu A")'
setflow v_botoes '{"operators":{"b":{"properties":{"texto":"","botoes!":["Só um"]}}}}'
mark; msg "/start"
chk "texto vazio vira 'Escolha:' e 1 botão só funciona" 'txt(ultimo("sendMessage"))=="Escolha:" && count(botoes(ultimo("sendMessage")))==1'
setflow v_botoes '{"operators":{"b":{"properties":{"botoes!":["1","2","3","4","5"]}}}}'
mark; msg "/start"
chk "5 botões: 3 linhas (2+2+1)" 'count(ultimo("sendMessage")["p"]["reply_markup"]["inline_keyboard"])==3'

secao "V1.5 Casos estranhos do grafo"
reset; setflow v_estranho
mark; msg "/start"
chk "mensagem vazia é pulada e o fluxo segue; ciclo A<->B termina (limite de 200 passos) sem travar" 'tem("Chegou depois da vazia") && count(tg("sendMessage"))>=2 && count(tg("sendMessage"))<=201 && semerros()'
reset; setflow v_sequencia '{"operators":{"start":{"properties":{"type":"nada"}}},"links!":[]}'
mark; msg "/start"
chk "fluxo sem ligação saindo do início: só cria o lead, nada é enviado" 'count(tg("sendMessage"))==0 && semerros() && q1("SELECT COUNT(*) FROM leads WHERE bot_id=?",[ids()["bot"]])==1'
reset; setflow v_sequencia '{"operators":{"m1":{"properties":{"type":"tipo_desconhecido"}}}}'
mark; msg "/start"
chk "bloco de tipo desconhecido é ignorado e o fluxo continua adiante" 'count(tg("sendPhoto"))>=1 && tem("Fim da sequência") && semerros()'
reset; setflow v_sequencia '{"links!":[{"fromOperator":"start","fromConnector":"output_1","toOperator":"bloco_que_nao_existe","toConnector":"input_1"}]}'
mark; msg "/start"
chk "link apontando pra bloco que não existe: encerra sem erro" 'count(tg("sendMessage"))==0 && semerros()'

secao "V1.6 Grupo e link mal configurados"
reset; setflow v_grupo_erro
mark; msg "/start"
chk "grupo sem id: mensagem de erro de configuração; link sem URL: idem; link/grupo sem texto de botão usam os textos padrão" 'tem("Grupo não configurado") && tem("link não configurado") && botoes(tg("sendMessage")[2])[0]["text"]=="Acessar" && botoes(tg("sendMessage")[3])[0]["text"]=="Entrar no Grupo"'
reset; setflow v_grupo_erro '{"operators":{"g1":{"properties":{"id_grupo":"-1001000000001"}}}}'
falha '{"createChatInviteLink":"Bad Request: not enough rights"}'
mark; msg "/start"
chk "convite falha: tenta o link permanente (exportChatInviteLink) e entrega" 'count(tg("exportChatInviteLink"))>=1 && strpos(botoes(tg("sendMessage")[0])[0]["url"],"https://t.me/+SIMPERM")===0'
falha '{"createChatInviteLink":"x","exportChatInviteLink":"y"}'
mark; msg "/start"
chk "convite e link permanente falham: avisa que o bot precisa ser admin do grupo" 'tem("Verifique se o bot é administrador")'
semfalha
echo; echo "FIM V1"
