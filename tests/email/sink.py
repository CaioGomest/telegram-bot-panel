#!/usr/bin/env python3
"""Servidor SMTP FALSO para testes: grava cada mensagem em /tmp/sink/N.eml e nao entrega nada.
   Comportamentos de teste: destinatario com "rejeitar" -> 550 no RCPT; usuario "ruim" -> 535 no AUTH; DATA demora SINK_ATRASO s;
   STARTTLS (certificado autoassinado) quando SINK_CERT/SINK_KEY existem."""
import asyncio, os, ssl, itertools, time

PORTA = int(os.environ.get('SINK_PORTA', '2526'))
ATRASO = float(os.environ.get('SINK_ATRASO', '0'))
CERT, KEY = os.environ.get('SINK_CERT'), os.environ.get('SINK_KEY')
PASTA = '/tmp/sink'
os.makedirs(PASTA, exist_ok=True)
contador = itertools.count(1)


async def linha(r):
    return (await r.readline()).decode('utf-8', 'replace').rstrip('\r\n')


async def cliente(r, w):
    async def dizer(t):
        w.write((t + '\r\n').encode()); await w.drain()
    usuario = ''
    de, para = '', []
    await dizer('220 sink ESMTP pronto')
    try:
        while True:
            l = await linha(r)
            if not l and r.at_eof():
                break
            c = l.upper()
            if c.startswith('EHLO') or c.startswith('HELO'):
                ext = ['250-sink', '250-AUTH LOGIN PLAIN', '250-8BITMIME']
                if CERT and not w.get_extra_info('sslcontext'):
                    ext.append('250-STARTTLS')
                ext.append('250 SIZE 20971520')
                for e in ext:
                    await dizer(e)
            elif c.startswith('STARTTLS') and CERT:
                await dizer('220 2.0.0 Ready to start TLS')
                ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(CERT, KEY)
                await w.start_tls(ctx)
            elif c.startswith('AUTH LOGIN'):
                import base64
                await dizer('334 VXNlcm5hbWU6')
                usuario = base64.b64decode(await linha(r) + '==').decode('utf-8', 'replace')
                await dizer('334 UGFzc3dvcmQ6')
                await linha(r)
                if usuario == 'ruim':
                    await dizer('535 5.7.8 Authentication failed')
                else:
                    await dizer('235 2.7.0 Authentication successful')
            elif c.startswith('MAIL FROM'):
                de = l; await dizer('250 2.1.0 OK')
            elif c.startswith('RCPT TO'):
                if 'rejeitar' in l.lower():
                    await dizer('550 5.1.1 User unknown')
                else:
                    para.append(l); await dizer('250 2.1.5 OK')
            elif c.startswith('DATA'):
                await dizer('354 End data with <CR><LF>.<CR><LF>')
                corpo = []
                while True:
                    x = await linha(r)
                    if x == '.':
                        break
                    corpo.append(x[1:] if x.startswith('..') else x)
                if ATRASO:
                    await asyncio.sleep(ATRASO)
                n = next(contador)
                with open('%s/%04d.eml' % (PASTA, n), 'w', encoding='utf-8') as f:
                    f.write('X-Sink-Usuario: %s\nX-Sink-De: %s\nX-Sink-Para: %s\nX-Sink-Hora: %s\n' % (usuario, de, ' | '.join(para), time.strftime('%H:%M:%S')))
                    f.write('\n'.join(corpo))
                await dizer('250 2.0.0 OK: queued as sink-%d' % n)
                de, para = '', []
            elif c.startswith('RSET'):
                de, para = '', []; await dizer('250 OK')
            elif c.startswith('NOOP'):
                await dizer('250 OK')
            elif c.startswith('QUIT'):
                await dizer('221 2.0.0 Bye'); break
            else:
                await dizer('502 5.5.2 comando nao reconhecido')
    except Exception:
        pass
    finally:
        try:
            w.close()
        except Exception:
            pass


async def main():
    srv = await asyncio.start_server(cliente, '127.0.0.1', PORTA)
    async with srv:
        await srv.serve_forever()

asyncio.run(main())
