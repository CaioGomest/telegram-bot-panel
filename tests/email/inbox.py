#!/usr/bin/env python3
"""Le as mensagens gravadas pelo sink. Uso: inbox.py lista | inbox.py codigo EMAIL (ultimo codigo de 6 digitos enviado para EMAIL)
   | inbox.py assuntos."""
import sys, glob, email, re
from email import policy

def ler(caminho):
    with open(caminho, 'rb') as f:
        return email.message_from_binary_file(f, policy=policy.default)

msgs = []
for c in sorted(glob.glob('/tmp/sink/*.eml')):
    m = ler(c)
    corpo = m.get_body(preferencelist=('plain',))
    texto = corpo.get_content() if corpo else ''
    msgs.append((c, m['X-Sink-Para'] or '', str(m['Subject'] or ''), texto))

modo = sys.argv[1] if len(sys.argv) > 1 else 'lista'
if modo == 'codigo':
    alvo = sys.argv[2].lower()
    for c, para, assunto, texto in reversed(msgs):
        if alvo in para.lower():
            achou = re.search(r'\b(\d{6})\b', texto)
            if achou:
                print(achou.group(1)); sys.exit(0)
    sys.exit(1)
elif modo == 'contem':
    # contem 'parte do assunto' 'texto' -> exit 0 se alguma mensagem com esse assunto traz o texto (corpo ja decodificado)
    for c, para, assunto, texto in msgs:
        if sys.argv[2].lower() in assunto.lower() and sys.argv[3] in texto:
            sys.exit(0)
    sys.exit(1)
elif modo == 'assuntos':
    for c, para, assunto, texto in msgs:
        print('%s | %s | %s' % (c[-8:-4], re.sub(r'.*<([^>]+)>.*', r'\1', para), assunto))
else:
    print(len(msgs))
