#!/bin/bash
# API 1: fluxos (criar/editar/validar preço mínimo/exportar/importar/limite de 50/excluir/vincular), segurança entre usuários.
source /tmp/pt/lib.sh
BOTID=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["bot"];')
UID_=$(php -r 'echo json_decode(file_get_contents("/tmp/pt/ids.json"),true)["uid"];')
OUTRO_FLUXO=$(php /tmp/pt/db.php "SELECT id FROM fluxos WHERE id_usuario <> $UID_ ORDER BY id LIMIT 1" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["id"] ?? 0;')
CONTA='q1("SELECT COUNT(*) FROM fluxos WHERE id_usuario=?",[ids()["uid"]])'

secao "A1.1 Acesso e segurança da API"
reset; sql "DELETE FROM fluxos WHERE id_usuario=$UID_" >/dev/null; mark; api GET listar_fluxos '' '' 0
chk "sem login: 401 Não autorizado" 'http()==401'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '' 'x')" 'sem_csrf=1'
chk "POST sem token CSRF: 403" 'http()==403'
mark; api GET listar_fluxos
chk "login ok: lista com limite_fluxos=50 e 0 fluxos do usuário" 'http()==200 && resp()["sucesso"]===true && resp()["limite_fluxos"]==50 && count(resp()["fluxos"])==0'
mark; api GET limites_pagamento
chk "limites_pagamento: mínimo 2,10 (split fixo 1,40 + tarifa 0,70), mínimo da API 1,50" 'abs(resp()["minimo"]-2.1)<0.001 && abs(resp()["tarifa"]-0.7)<0.001 && abs(resp()["minimo_api"]-1.5)<0.001'
mark; api GET acao_inexistente
chk "ação desconhecida: erro 404" 'http()==404'

secao "A1.2 Criar e editar fluxo Guiado"
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '' 'Guiado API')"
chk "cria fluxo guiado: sucesso, modo basico e dados completos gravados" 'resp()["sucesso"]===true && resp()["fluxo"]["modo"]=="basico" && count(resp()["fluxo"]["dados_fluxograma"]["planos"])==4 && '"$CONTA"'==1'
GID=$(php -r '$j=json_decode(file_get_contents("/tmp/pt/resp.json"),true); echo $j["body"]["fluxo"]["id"];')
chk "atividade 'Criou novo fluxo' registrada" 'q1("SELECT COUNT(*) FROM atividades WHERE id_usuario=? AND descricao LIKE \"Criou novo fluxo%\"",[ids()["uid"]])==1'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '{"suporte":"@novosuporte"}' 'Guiado API editado' $GID)"
chk "editar (mesmo id): atualiza nome e dados sem criar outro" 'resp()["sucesso"]===true && resp()["fluxo"]["nome"]=="Guiado API editado" && resp()["fluxo"]["dados_fluxograma"]["suporte"]=="@novosuporte" && '"$CONTA"'==1'
mark; php -r 'echo json_encode(["id"=>(int)$argv[1],"nome"=>"tenta virar visual","modo"=>"avancado","dados_fluxograma"=>["operators"=>new stdClass,"links"=>[]]]);' $GID > /tmp/pt/body.json; api POST salvar_fluxo "$(cat /tmp/pt/body.json)"
chk "tentar sobrescrever um guiado com dados de Editor Visual: 422 e o guiado fica intacto (4 planos, modo basico)" 'http()==422 && q1("SELECT modo FROM fluxos WHERE id=?",['$GID'])=="basico" && count(json_decode(q1("SELECT dados_fluxograma FROM fluxos WHERE id=?",['$GID']),true)["planos"])==4'
mark; api POST salvar_fluxo "$(payloadfluxo v_botoes '' 'visual como guiado')"
VBID=$(php -r '$j=json_decode(file_get_contents("/tmp/pt/resp.json"),true); echo $j["body"]["fluxo"]["id"] ?? 0;')
chk "criar visual normalmente funciona" 'resp()["sucesso"]===true'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '' 'guiado sobre visual' $VBID)"
chk "tentar sobrescrever um Editor Visual com dados Guiado: 422 e o visual fica intacto" 'http()==422 && q1("SELECT modo FROM fluxos WHERE id=?",['$VBID'])=="avancado" && isset(json_decode(q1("SELECT dados_fluxograma FROM fluxos WHERE id=?",['$VBID']),true)["operators"]["b"])'
mark; php -r 'require "/tmp/pt/flows.php"; [$m,$d]=guiado_base(); echo json_encode(["nome"=>"modo errado","modo"=>"avancado","dados_fluxograma"=>$d]);' > /tmp/pt/body.json; api POST salvar_fluxo "$(cat /tmp/pt/body.json)"
chk "criar declarando modo avancado mas com dados guiado: 422" 'http()==422'
sql "DELETE FROM fluxos WHERE id=$VBID" >/dev/null
mark; api GET obter_fluxo '' "id=$GID"
chk "obter_fluxo devolve o fluxo com dados" 'resp()["fluxo"]["id"]=='$GID' && is_array(resp()["fluxo"]["dados_fluxograma"])'
mark; api GET obter_fluxo '' "id=$OUTRO_FLUXO"
chk "fluxo de OUTRO usuário: 404 (não vaza)" 'http()==404'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '{"suporte":"hack"}' 'invasao' $OUTRO_FLUXO)"
chk "editar fluxo de outro usuário (id alheio): não altera o fluxo dele" 'q1("SELECT nome FROM fluxos WHERE id=?",['$OUTRO_FLUXO'])!="invasao"'

secao "A1.3 Preço mínimo (a gateway recusaria): editor/servidor bloqueiam"
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '{"planos!":[{"id":"x","nome":"Barato","valor":1.0,"dias_acesso":7,"unidade_acesso":"dias","id_grupo":""}],"ofertas":{"upsell":{"ativo":false},"downsell":{"ativo":false}}}' 'barato')"
chk "plano de R\$ 1,00: 422 com a mensagem do mínimo (R\$ 2,10) e NADA é gravado" 'http()==422 && strpos(resp()["mensagem"],"R$ 2,10")!==false && strpos(resp()["mensagem"],"Barato")!==false && '"$CONTA"'==1'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '{"packs":{"ativo":true,"itens!":[{"id":"k","ativo":true,"emoji":"","nome":"Pack barato","preco":1.8,"descricao":"","midia_tipo":"none","midia_path":"","id_grupo":""}]}}' 'packbarato')"
chk "pack de R\$ 1,80: bloqueado" 'http()==422 && strpos(resp()["mensagem"],"Pack barato")!==false'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '{"ofertas":{"upsell":{"ativo":true,"id_plano_destino":"p_mensal","desconto_percentual":90}}}' 'upbarato')"
chk "upsell que sai por R\$ 1,99 (19,90 com 90%): bloqueado" 'http()==422 && strpos(resp()["mensagem"],"Upsell")!==false'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '{"planos!":[{"id":"x","nome":"Justo","valor":2.1,"dias_acesso":7,"unidade_acesso":"dias","id_grupo":""}],"ofertas":{"upsell":{"ativo":false},"downsell":{"ativo":false}}}' 'justo')"
chk "plano exatamente no mínimo (R\$ 2,10): aceito" 'resp()["sucesso"]===true'

secao "A1.4 Fluxo Visual: sanitização e formato base64"
mark; api POST salvar_fluxo "$(payloadfluxo v_sequencia '{"operators":{"foto":{"properties":{"image_path":"config.php"}},"vid":{"properties":{"video_path":"uploads/../config.php"}},"aud":{"properties":{"audio_path":"uploads/ok.mp3"}}}}' 'Visual API')"
chk "salva visual; caminho de mídia fora do padrão é zerado, válido é mantido" 'resp()["sucesso"]===true && ($p=resp()["fluxo"]["dados_fluxograma"]["operators"]) && $p["foto"]["properties"]["image_path"]=="" && $p["vid"]["properties"]["video_path"]=="" && $p["aud"]["properties"]["audio_path"]=="uploads/ok.mp3"'
VID=$(php -r '$j=json_decode(file_get_contents("/tmp/pt/resp.json"),true); echo $j["body"]["fluxo"]["id"];')
chk "o modo gravado é 'avancado' (Editor Visual)" 'q1("SELECT modo FROM fluxos WHERE id=?",['$VID'])=="avancado"'
php -r 'require "/tmp/pt/flows.php"; [$m,$d]=v_botoes(); echo json_encode(["nome"=>"B64","modo"=>$m,"dados_fluxograma_b64"=>base64_encode(json_encode($d))]);' > /tmp/pt/body.json
mark; api POST salvar_fluxo "$(cat /tmp/pt/body.json)"
chk "dados_fluxograma_b64 (usado pra passar pelo WAF) é aceito" 'resp()["sucesso"]===true && count(resp()["fluxo"]["dados_fluxograma"]["operators"])>=3'
mark; api POST salvar_fluxo '{"nome":"B64 ruim","modo":"avancado","dados_fluxograma_b64":"###não-é-base64###"}'
chk "base64 inválido: 422 sem gravar" 'http()==422'
mark; api POST salvar_fluxo '{"nome":"sem dados","modo":"avancado"}'
chk "sem dados_fluxograma: cria fluxo vazio válido (operators/links vazios)" 'resp()["sucesso"]===true'

secao "A1.5 Exportar e importar"
mark; api GET exportar_fluxo '' "id=$GID"
chk "exportar guiado: versão, MODO, nome e dados" 'resp()["fluxo"]["versao"]==1 && resp()["fluxo"]["modo"]=="basico" && resp()["fluxo"]["nome"]=="Guiado API editado" && is_array(resp()["fluxo"]["dados_fluxograma"]["planos"])'
php -r '$j=json_decode(file_get_contents("/tmp/pt/resp.json"),true); file_put_contents("/tmp/pt/export_guiado.json", json_encode($j["body"]["fluxo"], JSON_UNESCAPED_UNICODE));'
mark; api GET exportar_fluxo '' "id=$VID"
chk "exportar visual: modo avancado" 'resp()["fluxo"]["modo"]=="avancado"'
php -r '$j=json_decode(file_get_contents("/tmp/pt/resp.json"),true); file_put_contents("/tmp/pt/export_visual.json", json_encode($j["body"]["fluxo"], JSON_UNESCAPED_UNICODE));'
mark; api GET exportar_fluxo '' "id=$OUTRO_FLUXO"
chk "exportar fluxo de outro usuário: 404" 'http()==404'
imp() { php -r '$d=json_decode(file_get_contents($argv[1]),true); echo json_encode(["nome"=>$d["nome"]." (importado)","descricao"=>$d["descricao"] ?? "","link_suporte"=>"","modo"=>$d["modo"] ?? "","dados_fluxograma_b64"=>base64_encode(json_encode($d["dados_fluxograma"]))]);' "$1"; }
ANTES=$(php /tmp/pt/db.php "SELECT COUNT(*) c FROM fluxos WHERE id_usuario=$UID_" | php -r '$j=json_decode(trim(stream_get_contents(STDIN)),true); echo $j["c"];')
mark; api POST importar_fluxo "$(imp /tmp/pt/export_guiado.json)"
chk "importar guiado: cria NOVO fluxo no modo guiado (basico) com os mesmos planos" 'resp()["sucesso"]===true && resp()["fluxo"]["modo"]=="basico" && count(resp()["fluxo"]["dados_fluxograma"]["planos"])==4 && '"$CONTA"'==('$ANTES'+1)'
mark; api POST importar_fluxo "$(imp /tmp/pt/export_visual.json)"
chk "importar visual: cria fluxo no modo avancado" 'resp()["sucesso"]===true && resp()["fluxo"]["modo"]=="avancado" && isset(resp()["fluxo"]["dados_fluxograma"]["operators"]["foto"])'
php -r '$d=json_decode(file_get_contents("/tmp/pt/export_guiado.json"),true); unset($d["modo"]); echo json_encode(["nome"=>"sem modo","dados_fluxograma"=>$d["dados_fluxograma"]]);' > /tmp/pt/body.json
mark; api POST importar_fluxo "$(cat /tmp/pt/body.json)"
chk "arquivo sem o campo modo (exportado antes da correção): guiado é reconhecido pela estrutura (planos)" 'resp()["sucesso"]===true && resp()["fluxo"]["modo"]=="basico"'
mark; api POST importar_fluxo '{"nome":"lixo","dados_fluxograma":{"foo":1}}'
chk "estrutura inválida: 422 'Estrutura do fluxo inválida' e nada criado" 'http()==422 && strpos(resp()["mensagem"],"inválida")!==false'
mark; api POST importar_fluxo '{"nome":"guiado lixo","modo":"basico","dados_fluxograma":{"operators":{},"links":[]}}'
chk "marcado como guiado mas sem planos: 422" 'http()==422'
php -r '$d=json_decode(file_get_contents("/tmp/pt/export_guiado.json"),true); $d["dados_fluxograma"]["planos"][0]["valor"]=1.0; $d["dados_fluxograma"]["ofertas"]["upsell"]["ativo"]=false; $d["dados_fluxograma"]["ofertas"]["downsell"]["ativo"]=false; echo json_encode(["nome"=>"barato","modo"=>"basico","dados_fluxograma"=>$d["dados_fluxograma"]]);' > /tmp/pt/body.json
mark; api POST importar_fluxo "$(cat /tmp/pt/body.json)"
chk "importar guiado com plano abaixo do mínimo: bloqueado como no salvar" 'http()==422 && strpos(resp()["mensagem"],"R$ 2,10")!==false'
mark; api POST importar_fluxo 'isso não é json'
chk "corpo que não é JSON: 422" 'http()==422'
php -r 'require "/tmp/pt/flows.php"; [$m,$d]=v_sequencia(); $d["operators"]["foto"]["properties"]["image_path"]="certificados/cert_1_1.pem"; echo json_encode(["nome"=>"mal","modo"=>"avancado","dados_fluxograma"=>$d]);' > /tmp/pt/body.json
mark; api POST importar_fluxo "$(cat /tmp/pt/body.json)"
chk "importar visual com caminho de mídia perigoso: caminho é zerado" 'resp()["sucesso"]===true && resp()["fluxo"]["dados_fluxograma"]["operators"]["foto"]["properties"]["image_path"]==""'

secao "A1.6 Limite de 50 fluxos por usuário"
# deixa o usuário com exatamente 49 fluxos e testa o 50º e o 51º, pelos 3 caminhos (criar, importar)
php -r 'require "/tmp/pt/common.php"; $u=ids()["uid"]; $n=(int)q1("SELECT COUNT(*) FROM fluxos WHERE id_usuario=?",[$u]); for($i=$n;$i<49;$i++){ $pdo->prepare("INSERT INTO fluxos (id_usuario,nome,descricao,modo,dados_fluxograma) VALUES (?,?,?,?,?)")->execute([$u,"enchimento $i","","avancado","{\"operators\":{},\"links\":[]}"]); } echo q1("SELECT COUNT(*) FROM fluxos WHERE id_usuario=?",[$u]),"\n";'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '' 'Fluxo número 50')"
chk "49 fluxos: o 50º ainda pode ser criado" 'resp()["sucesso"]===true && '"$CONTA"'==50'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '' 'Fluxo número 51')"
chk "50 fluxos: o 51º é recusado (422) com mensagem de limite" 'http()==422 && strpos(resp()["mensagem"],"Limite de 50 fluxos")!==false && '"$CONTA"'==50'
mark; api POST importar_fluxo "$(imp /tmp/pt/export_guiado.json)"
chk "50 fluxos: importar também é recusado" 'http()==422 && strpos(resp()["mensagem"],"Limite de 50 fluxos")!==false && '"$CONTA"'==50'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '{"suporte":"@editando"}' 'Editar no limite' $GID)"
chk "no limite, EDITAR um fluxo existente continua permitido" 'resp()["sucesso"]===true && '"$CONTA"'==50'
mark; api GET listar_fluxos
chk "listar_fluxos devolve 50 e o limite" 'count(resp()["fluxos"])==50 && resp()["limite_fluxos"]==50'
mark; api POST excluir_fluxo "{\"id\":$VID}"
chk "excluir um fluxo libera uma vaga" 'resp()["sucesso"]===true && '"$CONTA"'==49'
mark; api POST salvar_fluxo "$(payloadfluxo guiado_base '' 'Depois de excluir')"
chk "com vaga liberada, criar volta a funcionar" 'resp()["sucesso"]===true && '"$CONTA"'==50'
php -r 'require "/tmp/pt/common.php"; $u=ids()["uid"]; $pdo->prepare("DELETE FROM fluxos WHERE id_usuario=? AND nome LIKE \"enchimento%\"")->execute([$u]); echo "enchimento removido\n";'

secao "A1.7 Vincular bot, excluir fluxo, isolamento entre usuários"
reset
mark; api POST vincular_bot_fluxo "{\"id_bot\":$BOTID,\"id_fluxo\":$GID}"
chk "vincular bot ao fluxo" 'resp()["sucesso"]===true && q1("SELECT id_fluxo_conectado FROM bots WHERE id=?",[ids()["bot"]])=='$GID
mark; api GET bots_do_fluxo '' "id=$GID"
chk "bots_do_fluxo: o bot aparece como vinculado" 'count(resp()["vinculados"])==1 && resp()["vinculados"][0]["id"]==ids()["bot"]'
mark; api POST vincular_bot_fluxo "{\"id_bot\":$BOTID,\"id_fluxo\":$OUTRO_FLUXO}"
chk "vincular a um fluxo de OUTRO usuário: 404 e o vínculo não muda" 'http()==404 && q1("SELECT id_fluxo_conectado FROM bots WHERE id=?",[ids()["bot"]])=='$GID
mark; api POST vincular_bot_fluxo "{\"id_bot\":999999,\"id_fluxo\":$GID}"
chk "bot inexistente: 404" 'http()==404'
mark; api POST vincular_bot_fluxo "{\"id_bot\":$BOTID,\"id_fluxo\":0}"
chk "id_fluxo=0 desvincula" 'resp()["sucesso"]===true && q1("SELECT id_fluxo_conectado FROM bots WHERE id=?",[ids()["bot"]])===null'
api POST vincular_bot_fluxo "{\"id_bot\":$BOTID,\"id_fluxo\":$GID}"
mark; api POST excluir_fluxo "{\"id\":$GID}"
chk "excluir fluxo vinculado: bot fica sem fluxo (FK SET NULL)" 'resp()["sucesso"]===true && q1("SELECT id_fluxo_conectado FROM bots WHERE id=?",[ids()["bot"]])===null'
mark; api POST excluir_fluxo "{\"id\":$OUTRO_FLUXO}"
chk "excluir fluxo de outro usuário: 404 e o fluxo continua existindo" 'http()==404 && q1("SELECT COUNT(*) FROM fluxos WHERE id=?",['$OUTRO_FLUXO'])==1'
mark; api POST excluir_fluxo '{"id":0}'
chk "id inválido: 422" 'http()==422'
echo; echo "FIM A1"
