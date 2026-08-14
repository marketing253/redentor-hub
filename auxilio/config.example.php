<?php
/**
 * Auxílio Graduação — modelo de configuração
 * Copie este arquivo para config.php e preencha os valores reais.
 * config.php nunca é commitado no GitHub (está no .gitignore).
 */
return [

    // ---- Banco de dados -------------------------------------------------
    'db' => [
        'host'    => 'localhost',
        'base'    => 'AJUSTE_nome_do_banco',
        'usuario' => 'AJUSTE_usuario_do_banco',
        'senha'   => 'AJUSTE_senha_do_banco',
    ],

    // ---- Como descobrir quem está logado --------------------------------
    // 'aux_usuario' é a chave do login de teste. Mantenha também a chave que
    // o Redentor Hub usa na sessão; a primeira que existir é a que vale.
    // Dentro do Hub quem manda é a sessão do portal ($_SESSION['uid'] →
    // tabela portal_usuarios). 'aux_usuario' atende quem entra pelo entrar.php.
    'chaves_sessao' => ['aux_usuario', 'usuario', 'login', 'user', 'username'],

    // Integração com o Redentor Hub
    'hub' => [
        'tabela_usuarios' => 'portal_usuarios',
        // chave de permissão do card da contabilidade (perms_json do portal)
        'perm_contabilidade' => 'auxilio-contab',

        // Ao cadastrar um aluno que ainda não tem conta no Hub, criar a conta
        // dele automaticamente — liberada SÓ para o card do aluno.
        'criar_usuario' => true,
        // Todos os cards do menu. Ao criar um card novo no Hub, acrescente aqui,
        // senão ele nasce liberado para os alunos.
        'cards' => ['fuel','combustivel','iak','acidentes','comparativo','aderencia','drive',
                    'biart','lnt','salas','agenda','plano','tvindoor','chamados',
                    'auxilio','auxilio-contab','reunioes'],
        // O que o aluno enxerga:
        'cards_aluno' => ['auxilio'],
    ],

    // ---- Quem enxerga o painel da contabilidade -------------------------
    'papeis_contabilidade' => ['admin', 'contabilidade', 'rh'],
    'admins'               => ['admin', 'contabilidade'],

    // ---- Prazo do boleto --------------------------------------------------
    // Dia do mês em que o boleto precisa estar no sistema para o pagamento
    // ser processado. Mudou aqui? Rode "Recalcular prazos" no instalar.php.
    'dia_prazo' => 3,

    // ---- Senha do convite -------------------------------------------------
    // Senha que vai no e-mail de acesso. É padrão e conhecida: o sistema
    // obriga a trocar no primeiro acesso.
    'senha_padrao' => 'AJUSTE_uma_senha_padrao',

    // ---- Envio de e-mail (caixa criada na Hostinger) ---------------------
    'smtp' => [
        'host'           => 'smtp.hostinger.com',
        'porta'          => 465,                       // 465 = SSL | 587 = STARTTLS
        'usuario'        => 'AJUSTE_email@seudominio.com.br',
        'senha'          => 'AJUSTE_senha_do_email',
        'nome'           => 'Auxílio Graduação — Redentor',
        'responder_para' => 'AJUSTE_email@seudominio.com.br',
    ],
    'email_contabilidade' => ['AJUSTE_email@seudominio.com.br'],
    'url_sistema'         => 'https://AJUSTE_seudominio.com.br/',

    // ---- Feriados (mantidos para uso futuro) ------------------------------
    // O prazo agora é o dia fixo acima, então esta lista não interfere nele.
    'feriados' => [
        '2026-02-16', '2026-02-17', '2026-04-03', '2026-06-04',  // carnaval, sexta-santa, corpus christi
        '2027-02-08', '2027-02-09', '2027-03-26', '2027-05-27',
    ],

    // ---- Backup automático no Google Drive (backup.php por cron) ---------
    // modo 'oauth'   → client_id, client_secret e refresh_token de uma conta Google
    //                  (mesmas credenciais que o Hub já usa para o Drive)
    // modo 'servico' → JSON de conta de serviço; SÓ funciona em Drive compartilhado
    'drive' => [
        'ativo'              => false,      // ligue depois de preencher as credenciais
        'modo'               => 'oauth',
        'client_id'          => '',
        'client_secret'      => '',
        'refresh_token'      => '',
        'arquivo_credencial' => '',         // caminho do JSON, só no modo 'servico'
        'pasta_id'           => 'AJUSTE_id_da_pasta_no_drive',
        // drive.file = só mexe nos arquivos que o próprio sistema criar (recomendado).
        // Se o envio falhar dizendo que não achou a pasta, troque por
        // 'https://www.googleapis.com/auth/drive'
        'escopo'             => 'https://www.googleapis.com/auth/drive.file',
    ],

    // ---- Onde os boletos e comprovantes ficam gravados -------------------
    // Vazio = pasta uploads_auxilio dentro do módulo (protegida por .htaccess).
    // Mais seguro: um caminho FORA do public_html, aí o .htaccess nem é preciso.
    // Ex.: '/home/uXXXXXX/domains/seudominio.com.br/uploads_auxilio'
    'dir_uploads' => '',

    // ---- Somente para o ambiente de teste --------------------------------
    // Chave pedida em instalar.php/login.php. Troque antes de subir e apague
    // instalar.php/login.php quando o módulo entrar de vez no Hub.
    'chave_teste' => 'AJUSTE_uma_chave_dificil_de_adivinhar',
];
