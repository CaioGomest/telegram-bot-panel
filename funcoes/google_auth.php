<?php
declare(strict_types=1);

require_once __DIR__ . '/configuracoes.php';
require_once __DIR__ . '/criptografia.php';

/**
 * Login com Google (OAuth 2.0 / OpenID Connect), "Authorization Code" flow.
 *
 * As credenciais (Client ID/Secret) ficam no banco, na tabela `configuracoes` -- mesmo
 * lugar de nome/logo/favicon -- em vez de em config.php. Faz sentido aqui: numa instalação
 * white-label, cada revenda roda no seu próprio domínio e precisa do seu próprio app OAuth
 * cadastrado no Google Cloud (o Google amarra a credencial ao domínio/redirect_uri
 * registrado), então isso é configuração de cada instalação, não do código-fonte. O Client
 * Secret é criptografado com a mesma chave (CHAVE_CRIPTOGRAFIA_GATEWAYS) já usada pros
 * segredos de gateway de pagamento -- é um segredo da mesma categoria.
 *
 * O Client ID não é sigiloso (ele aparece na URL de redirecionamento pro Google, visível no
 * navegador de qualquer usuário) -- só o Secret precisa de proteção.
 */

function googleClientId(): string
{
    return configSistema('google_client_id');
}

function googleClientSecret(): string
{
    return descriptografarSegredo(configSistema('google_client_secret'));
}

function googleLoginConfigurado(): bool
{
    return googleClientId() !== '' && googleClientSecret() !== '';
}

function definirCredenciaisGoogle(string $client_id, string $client_secret): bool
{
    $ok1 = definirConfigSistema('google_client_id', trim($client_id));
    // Client Secret vazio não sobrescreve o que já tinha salvo -- mesma regra usada nos
    // formulários de gateway: campo em branco significa "mantém", nunca "apaga".
    if (trim($client_secret) !== '') {
        $ok2 = definirConfigSistema('google_client_secret', criptografarSegredo(trim($client_secret)));
    } else {
        $ok2 = true;
    }
    return $ok1 && $ok2;
}

/**
 * A URL exata que precisa estar cadastrada no Google Cloud Console como "URI de
 * redirecionamento autorizado" -- se não bater caractere por caractere (incluindo http vs
 * https), o Google recusa o login com invalid_redirect_uri.
 */
function googleRedirectUri(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https://' : 'http://') . $host . '/google_callback';
}

/**
 * Monta a URL de autorização do Google. $state é um valor aleatório de uso único que a
 * gente guarda na sessão e confere de volta no callback -- é o equivalente ao token CSRF
 * pra esse fluxo (sem ele, um atacante poderia iniciar o "code" dele numa aba e fazer a
 * vítima abrir o callback com esse code, plantando a conta google do atacante na sessão da
 * vítima).
 */
function googleUrlAutorizacao(string $state): string
{
    $params = [
        'client_id'     => googleClientId(),
        'redirect_uri'  => googleRedirectUri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        // Sempre pede a tela de escolha de conta -- sem isto, quem tem várias contas Google
        // logadas no navegador às vezes é logado direto na última usada, sem chance de
        // escolher qual conta quer usar aqui.
        'prompt'        => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Troca o "code" de autorização (que veio na URL de callback) pelo access_token, direto
 * servidor-a-servidor com o Google -- o code sozinho não vale nada sem o Client Secret, que
 * nunca é exposto ao navegador.
 */
function googleTrocarCodigoPorToken(string $code): ?array
{
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code'          => $code,
        'client_id'     => googleClientId(),
        'client_secret' => googleClientSecret(),
        'redirect_uri'  => googleRedirectUri(),
        'grant_type'    => 'authorization_code',
    ]));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resposta = curl_exec($ch);
    $codigo_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resposta === false || $codigo_http !== 200) {
        error_log('[google_auth] falha ao trocar code por token (http=' . $codigo_http . '): ' . ($resposta ?: curl_error($ch)));
        return null;
    }
    $dados = json_decode($resposta, true);
    return isset($dados['access_token']) ? $dados : null;
}

/**
 * Busca os dados da conta (sub/id, email, nome) usando o access_token recém-obtido. Confere
 * email_verified explicitamente: sem isso, um provedor de e-mail que deixasse qualquer um
 * "reivindicar" um endereço sem confirmar dono daria pra criar/entrar numa conta daqui
 * fingindo ser o e-mail de outra pessoa -- o Google normalmente sempre verifica, mas checar
 * aqui é a diferença entre confiar no Google e confiar cegamente num campo de um JSON.
 */
function googleObterDadosUsuario(string $access_token): ?array
{
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resposta = curl_exec($ch);
    $codigo_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resposta === false || $codigo_http !== 200) {
        error_log('[google_auth] falha ao buscar dados do usuário (http=' . $codigo_http . ')');
        return null;
    }
    $dados = json_decode($resposta, true);
    if (empty($dados['sub']) || empty($dados['email']) || empty($dados['email_verified'])) {
        error_log('[google_auth] resposta do Google sem sub/email/email_verified.');
        return null;
    }
    return $dados;
}
