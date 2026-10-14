#!/bin/bash
# API 2: bots (criar/atualizar/limite de 50/excluir), eventos de grupo e contato no webhook.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
OUTRO_BOT=$(php /tmp/pt/db.php "SELECT id FROM bots WHERE id_usuario <> $UID_ ORDER BY id LIMIT 1" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["id"] ?? 0;')
CONTA='q1("SELECT COUNT(*) FROM bots WHERE id_usuario=?",[ids()["uid"]])'
limpa_bots() { sql "DELETE FROM bots WHERE id_usuario=$UID_ AND id<>$BOTID" >/dev/null; }

secao "A2.1 Criar e atualizar bot"
reset; limpa_bots
mark; api GET listar_bots
chk "listar_bots traz o bot de teste com leads_7d, vendas_7d e o limite (50)" 'http()==200 && resp()["limite_bots"]==50 && count(resp()["bots"])==1 && isset(resp()["bots"][0]["leads_7d"]) && isset(resp()["bots"][0]["vendas_7d"])'
mark; api POST salvar_bot '{"token":"SIMBOT:NOVO-0001"}'
chk "criar bot novo (token validado no 'Telegram' simulado): webhook ativado e bot gravado" 'resp()["sucesso"]===true && resp()["webhook_definido"]===true && strpos(resp()["url_webhook"],"webhook.php?token=")!==false && '"$CONTA"'==2'
chk "setWebhook foi chamado com o segredo (secret_token) do bot" '($w=tg("setWebhook")) && count($w)==1 && !empty($w[0]["p"]["secret_token"])'
chk "dados do bot vieram do getMe (username/nome) e atividade 'Criou o bot'" '($b=resp()["bot"]) && strpos($b["nome_usuario"],"sim_")===0 && $b["id_usuario"]==ids()["uid"] && q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND descricao LIKE \"Criou o bot%\"",[ids()["uid"]])==1'
mark; api POST salvar_bot '{"token":"SIMBOT:NOVO-0001"}'
chk "salvar o MESMO token de novo: atualiza (não cria outro) — 'Atualizou o bot'" 'resp()["sucesso"]===true && '"$CONTA"'==2 && q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND descricao LIKE \"Atualizou o bot%\"",[ids()["uid"]])==1'
mark; falha '{"getMe":"Unauthorized"}'; api POST salvar_bot '{"token":"SIMBOT:RUIM"}'; semfalha
chk "token que o Telegram recusa: 400 com mensagem, nada gravado" 'http()==400 && '"$CONTA"'==2'
mark; api POST salvar_bot '{"token":""}'
chk "token vazio: 422" 'http()==422'

secao "A2.2 Limite de 50 bots por usuário"
php -r 'require "/tmp/pt/common.php"; $u=ids()["uid"]; $n=(int)q1("SELECT COUNT(*) FROM bots WHERE id_usuario=?",[$u]); for($i=$n;$i<49;$i++){ $pdo->prepare("INSERT INTO bots (id_usuario,token,id_bot_telegram,nome_usuario,primeiro_nome) VALUES (?,?,?,?,?)")->execute([$u,"FILL:enchimento$i",8000000+$i,"fill_$i","Fill $i"]); } echo q1("SELECT COUNT(*) FROM bots WHERE id_usuario=?",[$u]),"\n";' >/dev/null
mark; api POST salvar_bot '{"token":"SIMBOT:NOVO-0050"}'
chk "49 bots: o 50º pode ser conectado" 'resp()["sucesso"]===true && '"$CONTA"'==50'
mark; api POST salvar_bot '{"token":"SIMBOT:NOVO-0051"}'
chk "50 bots: o 51º é recusado (422) com a mensagem do limite e NADA é gravado" 'http()==422 && strpos(resp()["mensagem"],"Limite de 50 bots")!==false && '"$CONTA"'==50 && q1("SELECT COUNT(*) FROM bots WHERE token=\"SIMBOT:NOVO-0051\"")==0'
mark; api POST salvar_bot '{"token":"SIMBOT:NOVO-0050"}'
chk "no limite, atualizar um bot que já existe continua permitido" 'resp()["sucesso"]===true && '"$CONTA"'==50'
mark; api GET listar_bots
chk "listar_bots: 50 bots e limite_bots=50" 'count(resp()["bots"])==50 && resp()["limite_bots"]==50'
mark; api POST excluir_bot '{"id":'"$(php /tmp/pt/db.php "SELECT id FROM bots WHERE token='FILL:enchimento5'" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["id"];')"'}'
chk "excluir um bot libera vaga" 'resp()["sucesso"]===true && '"$CONTA"'==49'
mark; api POST salvar_bot '{"token":"SIMBOT:NOVO-0052"}'
chk "com vaga liberada, conectar volta a funcionar" 'resp()["sucesso"]===true && '"$CONTA"'==50'
mark; api POST excluir_bot "{\"id\":$OUTRO_BOT}"
chk "excluir bot de OUTRO usuário: 404 e o bot continua existindo" 'http()==404 && q1("SELECT COUNT(*) FROM bots WHERE id=?",['$OUTRO_BOT'])==1'
mark; api POST excluir_bot '{"id":0}'
chk "id inválido: 422" 'http()==422'
limpa_bots

secao "A2.3 Eventos de grupo e contato no webhook"
reset; setflow guiado_base
GRP='-1009990000001'
upd() { echo "$1"; }
mark; hook "{\"update_id\":1,\"my_chat_member\":{\"chat\":{\"id\":$GRP,\"title\":\"Grupo VIP Teste\",\"type\":\"supergroup\"},\"new_chat_member\":{\"status\":\"administrator\"}}}"
chk "bot adicionado como admin a um grupo: grupo registrado em bot_grupos + atividade" 'q1("SELECT titulo FROM bot_grupos WHERE bot_id=? AND id_telegram=\"-1009990000001\"",[ids()["bot"]])=="Grupo VIP Teste" && q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND titulo=\"Grupo\"",[ids()["uid"]])==1'
mark; hook "{\"update_id\":2,\"my_chat_member\":{\"chat\":{\"id\":$GRP,\"title\":\"Grupo VIP Renomeado\",\"type\":\"supergroup\"},\"new_chat_member\":{\"status\":\"member\"}}}"
chk "mesmo grupo de novo: atualiza o título (sem duplicar)" 'q1("SELECT COUNT(*) FROM bot_grupos WHERE bot_id=?",[ids()["bot"]])==1 && q1("SELECT titulo FROM bot_grupos WHERE bot_id=?",[ids()["bot"]])=="Grupo VIP Renomeado"'
mark; hook "{\"update_id\":3,\"message\":{\"message_id\":1,\"chat\":{\"id\":$GRP,\"title\":\"Grupo VIP Título Novo\",\"type\":\"supergroup\"},\"new_chat_title\":\"Grupo VIP Título Novo\"}}"
chk "mudança de título do grupo: atualiza o nome" 'q1("SELECT titulo FROM bot_grupos WHERE bot_id=?",[ids()["bot"]])=="Grupo VIP Título Novo"'
mark; hook "{\"update_id\":4,\"message\":{\"message_id\":1,\"chat\":{\"id\":$GRP,\"type\":\"group\"},\"migrate_to_chat_id\":-1008880000001}}"
chk "grupo migrou pra supergrupo: troca o id e o tipo" 'q1("SELECT tipo FROM bot_grupos WHERE bot_id=? AND id_telegram=\"-1008880000001\"",[ids()["bot"]])=="supergroup"'
mark; hook "{\"update_id\":5,\"my_chat_member\":{\"chat\":{\"id\":-1008880000001,\"title\":\"x\",\"type\":\"supergroup\"},\"new_chat_member\":{\"status\":\"kicked\"}}}"
chk "bot removido do grupo: grupo some da lista + atividade" 'q1("SELECT COUNT(*) FROM bot_grupos WHERE bot_id=?",[ids()["bot"]])==0 && q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND descricao LIKE \"Bot removido%\"",[ids()["uid"]])==1'
mark; hook "{\"update_id\":6,\"message\":{\"message_id\":2,\"chat\":{\"id\":-1007770000001,\"title\":\"Grupo via mensagem\",\"type\":\"group\"},\"new_chat_members\":[{\"id\":7000001,\"is_bot\":true}]}}"
chk "bot entra no grupo (mensagem de serviço, backup do my_chat_member): grupo registrado" 'q1("SELECT titulo FROM bot_grupos WHERE bot_id=? AND id_telegram=\"-1007770000001\"",[ids()["bot"]])=="Grupo via mensagem"'
mark; msg "/start" "Maria"
hook "{\"update_id\":7,\"message\":{\"message_id\":3,\"from\":{\"id\":777000111,\"first_name\":\"Maria\"},\"chat\":{\"id\":777000111,\"type\":\"private\"},\"contact\":{\"phone_number\":\"+55 (11) 98888-7777\",\"first_name\":\"Maria\"}}}"
chk "cliente compartilha o contato: telefone salvo só com dígitos no lead" 'q1("SELECT telefone FROM leads WHERE bot_id=?",[ids()["bot"]])=="5511988887777"'
mark; hook "{\"update_id\":8,\"edited_message\":{\"message_id\":3,\"chat\":{\"id\":777000111},\"text\":\"x\"}}"
chk "update de tipo não tratado (mensagem editada): ignorado sem erro" 'count(tg("sendMessage"))==0 && semerros()'
mark; hook "{\"update_id\":9,\"channel_post\":{\"message_id\":3,\"chat\":{\"id\":-100123,\"type\":\"channel\"},\"text\":\"post\"}}"
chk "post de canal: ignorado sem erro" 'count(tg("sendMessage"))==0 && semerros()'
mark; hook "isso nao e json"
chk "corpo que não é JSON: ignorado sem erro" 'semerros()'
sql "DELETE FROM bot_grupos WHERE bot_id=$BOTID" >/dev/null
echo; echo "FIM A2"
