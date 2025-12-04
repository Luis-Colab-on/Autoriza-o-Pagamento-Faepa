<?php
/**
 * Módulo de chat FAEPA – threads 1:1 com regras de visibilidade.
 * Cria tabelas customizadas, expõe endpoints AJAX e injeta a UI no footer.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Faepa_Chatbox {
    const NONCE_ACTION  = 'faepa_chat_nonce';
    const SCRIPT_HANDLE = 'faepa-chatbox';
    const STYLE_HANDLE  = 'faepa-chatbox';
    const MAX_MESSAGES  = 50;

    private static $table_threads  = '';
    private static $table_messages = '';
    private static $finance_user   = null;

    /**
     * Inicializa hooks do módulo.
     */
    public static function init() {
        global $wpdb;
        self::$table_threads  = $wpdb->prefix . 'faepa_chat_threads';
        self::$table_messages = $wpdb->prefix . 'faepa_chat_messages';

        // Registra criação das tabelas na ativação do plugin principal.
        register_activation_hook( self::plugin_file(), array( __CLASS__, 'activate' ) );

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( __CLASS__, 'render_chat_shell' ) );

        add_action( 'wp_ajax_faepa_chat_contacts', array( __CLASS__, 'ajax_contacts' ) );
        add_action( 'wp_ajax_faepa_chat_messages', array( __CLASS__, 'ajax_messages' ) );
        add_action( 'wp_ajax_faepa_chat_send', array( __CLASS__, 'ajax_send' ) );
        add_action( 'wp_ajax_faepa_chat_mark_read', array( __CLASS__, 'ajax_mark_read' ) );
        add_action( 'wp_ajax_faepa_chat_unread_count', array( __CLASS__, 'ajax_unread_count' ) );
    }

    /**
     * Caminho do arquivo principal do plugin (necessário para register_activation_hook).
     */
    private static function plugin_file() {
        return dirname( __DIR__ ) . '/fomulario_pagamento_faepa.php';
    }

    /**
     * URL base do plugin.
     */
    private static function plugin_url() {
        return plugin_dir_url( self::plugin_file() );
    }

    /**
     * ID do usuário "financeiro" (canal único).
     */
    private static function get_finance_user_id() {
        $user = self::get_finance_user();
        return $user ? (int) $user->ID : 0;
    }

    /**
     * Verifica se a página atual é o dashboard financeiro (shortcode [apf_inbox]).
     */
    private static function is_finance_desk_page() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        global $post;
        if ( empty( $post ) || ! isset( $post->post_content ) ) {
            return false;
        }
        return (bool) has_shortcode( $post->post_content, 'apf_inbox' );
    }

    /**
     * Permissão mínima para atuar como "financeiro" no chat (dashboard).
     */
    private static function current_user_can_finance_desk() {
        return is_user_logged_in();
    }

    /**
     * Confirma se a requisição é do dashboard financeiro (flag + permissão).
     */
    private static function is_finance_desk_request() {
        return ! empty( $_POST['is_desk'] ) && self::current_user_can_finance_desk();
    }

    /**
     * Contexto do usuário no chat: quem está logado e quem atua na thread.
     */
    private static function get_chat_user_context() {
        $real_user_id = get_current_user_id();
        if ( ! $real_user_id ) {
            return null;
        }

        if ( self::is_finance_desk_request() ) {
            $finance_id = self::get_finance_user_id();
            if ( ! $finance_id ) {
                return null;
            }
            return array(
                'real_user_id'     => $real_user_id,
                'chat_user_id'     => $finance_id,
                'is_finance_desk'  => true,
            );
        }

        return array(
            'real_user_id'     => $real_user_id,
            'chat_user_id'     => $real_user_id,
            'is_finance_desk'  => false,
        );
    }

    /**
     * Cria/atualiza tabelas customizadas usando dbDelta.
     */
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        self::$table_threads  = $wpdb->prefix . 'faepa_chat_threads';
        self::$table_messages = $wpdb->prefix . 'faepa_chat_messages';

        $charset = $wpdb->get_charset_collate();

        $sql_threads = "CREATE TABLE " . self::$table_threads . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            user1_id bigint(20) unsigned NOT NULL,
            user2_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY user1_id (user1_id),
            KEY user2_id (user2_id),
            KEY updated_at (updated_at)
        ) $charset;";

        $sql_messages = "CREATE TABLE " . self::$table_messages . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            thread_id bigint(20) unsigned NOT NULL,
            sender_id bigint(20) unsigned NOT NULL,
            message_text longtext NULL,
            attachment_id bigint(20) unsigned NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY thread_id (thread_id),
            KEY sender_id (sender_id),
            KEY is_read (is_read)
        ) $charset;";

        dbDelta( $sql_threads );
        dbDelta( $sql_messages );
    }

    /**
     * Garante que as tabelas existam antes de qualquer operação.
     */
    private static function ensure_tables() {
        global $wpdb;
        if ( empty( self::$table_threads ) || empty( self::$table_messages ) ) {
            self::$table_threads  = $wpdb->prefix . 'faepa_chat_threads';
            self::$table_messages = $wpdb->prefix . 'faepa_chat_messages';
        }

        $threads_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::$table_threads ) );
        $messages_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::$table_messages ) );

        if ( $threads_exists !== self::$table_threads || $messages_exists !== self::$table_messages ) {
            self::activate();
        }
    }

    /**
     * Enfileira scripts/estilos do chat para usuários logados.
     */
    public static function enqueue_assets() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $base_url = self::plugin_url() . 'includes/';

        wp_enqueue_style(
            self::STYLE_HANDLE,
            $base_url . 'faepa-chatbox.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $base_url . 'faepa-chatbox.js',
            array(),
            '1.0.0',
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'faepaChatbox',
            array(
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
                'userId'   => get_current_user_id(),
                'userRole' => self::get_user_role(),
                'iconUrl'  => self::plugin_url() . 'imgs/chatbox-preta.svg',
                'isFinanceDesk' => self::is_finance_desk_page(),
                'strings'  => array(
                    'contacts' => 'Contatos',
                    'search'   => 'Buscar por nome ou e-mail',
                    'emptyList'=> 'Nenhum contato disponível.',
                    'emptyMsg' => 'Nenhuma mensagem ainda.',
                    'send'     => 'Enviar',
                ),
            )
        );
    }

    /**
     * Injeta HTML base (botão flutuante + modal) no footer.
     */
    public static function render_chat_shell() {
        if ( ! is_user_logged_in() ) {
            return;
        } ?>
        <div id="faepaChatboxRoot" class="faepa-chat-root" aria-live="polite">
            <button id="faepaChatboxButton" class="faepa-chat-floating" aria-label="Abrir chat">
                <img src="<?php echo esc_url( self::plugin_url() . 'imgs/chatbox-preta.svg' ); ?>" alt="Chat" />
                <span id="faepaChatboxBadge" class="faepa-chat-badge" hidden>0</span>
            </button>

            <div id="faepaChatboxOverlay" class="faepa-chat-overlay" hidden>
                <div class="faepa-chat-modal" role="dialog" aria-modal="true" aria-labelledby="faepaChatboxTitle">
                    <div class="faepa-chat-modal__header">
                        <h3 id="faepaChatboxTitle">Chat</h3>
                        <button type="button" id="faepaChatboxClose" class="faepa-chat-close" aria-label="Fechar">×</button>
                    </div>
                    <div class="faepa-chat-modal__body">
                        <aside class="faepa-chat-sidebar">
                            <div class="faepa-chat-search">
                                <input id="faepaChatSearch" type="text" placeholder="Buscar por nome ou e-mail" />
                            </div>
                            <div id="faepaChatContacts" class="faepa-chat-contacts" role="listbox"></div>
                        </aside>
                        <section class="faepa-chat-main">
                            <div id="faepaChatHeader" class="faepa-chat-main__header">Selecione um contato</div>
                            <div id="faepaChatMessages" class="faepa-chat-messages" data-empty="Nenhuma mensagem ainda."></div>
                            <form id="faepaChatForm" class="faepa-chat-form">
                                <textarea id="faepaChatInput" rows="2" placeholder="Digite sua mensagem"></textarea>
                                <div class="faepa-chat-form__actions">
                                    <input type="file" id="faepaChatFile" accept="image/*" />
                                    <button type="submit"><?php echo esc_html__( 'Enviar', 'faepa' ); ?></button>
                                </div>
                            </form>
                        </section>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Lista contatos visíveis e contagem de não lidas por thread.
     */
    public static function ajax_contacts() {
        check_ajax_referer( self::NONCE_ACTION );
        $context = self::get_chat_user_context();
        if ( ! $context ) {
            wp_send_json_error( array( 'message' => 'Sem permissão.' ), 403 );
        }

        self::ensure_tables();

        $search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $contacts = $context['is_finance_desk']
            ? self::get_contacts_for_finance_desk( $context['chat_user_id'], $search )
            : self::get_contacts_for_finance_target( $context['chat_user_id'], $search );

        wp_send_json_success( array( 'contacts' => $contacts ) );
    }

    /**
     * AJAX: Busca mensagens de uma thread ou cria thread se permitido.
     */
    public static function ajax_messages() {
        check_ajax_referer( self::NONCE_ACTION );
        global $wpdb;

        $context = self::get_chat_user_context();
        if ( ! $context ) {
            wp_send_json_error( array( 'message' => 'Sem permissão.' ), 403 );
        }

        self::ensure_tables();

        $user_id    = $context['chat_user_id'];
        $thread_id  = isset( $_POST['thread_id'] ) ? absint( $_POST['thread_id'] ) : 0;
        $contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;

        if ( ! $user_id || ( $thread_id <= 0 && $contact_id <= 0 ) ) {
            wp_send_json_error( array( 'message' => 'Parâmetros inválidos.' ), 400 );
        }

        if ( $thread_id <= 0 && $contact_id > 0 ) {
            $thread_id = self::maybe_get_or_create_thread( $user_id, $contact_id );
        }

        if ( $thread_id <= 0 || ! self::user_in_thread( $user_id, $thread_id ) ) {
            wp_send_json_error( array( 'message' => 'Thread não encontrada.' ), 404 );
        }

        // Busca as últimas mensagens (limite simples) e reordena em ASC para exibição.
        $messages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sender_id, message_text, attachment_id, created_at, is_read
                 FROM " . self::$table_messages . "
                 WHERE thread_id = %d
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d",
                $thread_id,
                self::MAX_MESSAGES
            ),
            ARRAY_A
        );
        $messages = array_reverse( $messages );

        $payload = array();
        foreach ( $messages as $msg ) {
            $attachment_url = '';
            if ( ! empty( $msg['attachment_id'] ) ) {
                $attachment_url = wp_get_attachment_url( (int) $msg['attachment_id'] );
            }
            $payload[] = array(
                'id'         => (int) $msg['id'],
                'sender_id'  => (int) $msg['sender_id'],
                'text'       => $msg['message_text'],
                'attachment' => $attachment_url,
                'created_at' => $msg['created_at'],
                'is_own'     => ( (int) $msg['sender_id'] === $user_id ),
            );
        }

        // Marca como lidas as mensagens recebidas nesta thread
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::$table_messages . " SET is_read = 1
                 WHERE thread_id = %d AND sender_id <> %d AND is_read = 0",
                $thread_id,
                $user_id
            )
        );

        $contact_id = self::get_thread_other_user( $thread_id, $user_id );
        $contact    = self::get_user_payload( $contact_id );

        wp_send_json_success(
            array(
                'thread_id' => $thread_id,
                'contact'   => $contact,
                'messages'  => $payload,
            )
        );
    }

    /**
     * AJAX: Envia mensagem (texto e/ou imagem).
     */
    public static function ajax_send() {
        check_ajax_referer( self::NONCE_ACTION );
        global $wpdb;

        $context = self::get_chat_user_context();
        if ( ! $context ) {
            wp_send_json_error( array( 'message' => 'Sem permissão.' ), 403 );
        }

        self::ensure_tables();

        $user_id    = $context['chat_user_id'];
        $thread_id  = isset( $_POST['thread_id'] ) ? absint( $_POST['thread_id'] ) : 0;
        $contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
        $message    = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';

        if ( ! $user_id || ( $thread_id <= 0 && $contact_id <= 0 ) ) {
            wp_send_json_error( array( 'message' => 'Parâmetros inválidos.' ), 400 );
        }

        if ( $thread_id <= 0 && $contact_id > 0 ) {
            $thread_id = self::maybe_get_or_create_thread( $user_id, $contact_id );
        }

        if ( $thread_id <= 0 || ! self::user_in_thread( $user_id, $thread_id ) ) {
            wp_send_json_error( array( 'message' => 'Thread não encontrada.' ), 404 );
        }

        // Upload de imagem (opcional)
        $attachment_id = 0;
        if ( ! empty( $_FILES['attachment']['name'] ) ) {
            if ( ! function_exists( 'media_handle_upload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
            $allowed = array( 'image/jpeg', 'image/png', 'image/gif' );
            $check   = wp_check_filetype_and_ext( $_FILES['attachment']['tmp_name'], $_FILES['attachment']['name'] );
            if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
                wp_send_json_error( array( 'message' => 'Apenas imagens são permitidas.' ), 400 );
            }
            $attachment_id = media_handle_upload( 'attachment', 0 );
            if ( is_wp_error( $attachment_id ) ) {
                wp_send_json_error( array( 'message' => 'Falha ao enviar a imagem.' ), 400 );
            }
        }

        if ( '' === trim( $message ) && $attachment_id <= 0 ) {
            wp_send_json_error( array( 'message' => 'Informe uma mensagem ou anexe uma imagem.' ), 400 );
        }

        $now = current_time( 'mysql' );
        $wpdb->insert(
            self::$table_messages,
            array(
                'thread_id'    => $thread_id,
                'sender_id'    => $user_id,
                'message_text' => wp_kses_post( $message ),
                'attachment_id'=> $attachment_id ?: null,
                'created_at'   => $now,
                'is_read'      => 0,
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%d' )
        );

        $wpdb->update(
            self::$table_threads,
            array( 'updated_at' => $now ),
            array( 'id' => $thread_id ),
            array( '%s' ),
            array( '%d' )
        );

        wp_send_json_success(
            array(
                'thread_id' => $thread_id,
                'message'   => array(
                    'id'         => $wpdb->insert_id,
                    'sender_id'  => $user_id,
                    'text'       => $message,
                    'attachment' => $attachment_id ? wp_get_attachment_url( $attachment_id ) : '',
                    'created_at' => $now,
                    'is_own'     => true,
                ),
            )
        );
    }

    /**
     * AJAX: Marca mensagens como lidas para a thread atual.
     */
    public static function ajax_mark_read() {
        check_ajax_referer( self::NONCE_ACTION );
        global $wpdb;

        $context = self::get_chat_user_context();
        if ( ! $context ) {
            wp_send_json_error( array( 'message' => 'Sem permissão.' ), 403 );
        }

        self::ensure_tables();

        $user_id   = $context['chat_user_id'];
        $thread_id = isset( $_POST['thread_id'] ) ? absint( $_POST['thread_id'] ) : 0;

        if ( ! $user_id || $thread_id <= 0 || ! self::user_in_thread( $user_id, $thread_id ) ) {
            wp_send_json_error( array( 'message' => 'Thread inválida.' ), 400 );
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::$table_messages . " SET is_read = 1
                 WHERE thread_id = %d AND sender_id <> %d AND is_read = 0",
                $thread_id,
                $user_id
            )
        );

        wp_send_json_success();
    }

    /**
     * AJAX: Contagem total de mensagens não lidas do usuário.
     */
    public static function ajax_unread_count() {
        check_ajax_referer( self::NONCE_ACTION );
        global $wpdb;

        $context = self::get_chat_user_context();
        if ( ! $context ) {
            wp_send_json_error( array( 'message' => 'Sem permissão.' ), 403 );
        }

        self::ensure_tables();

        $user_id = $context['chat_user_id'];

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(m.id)
                 FROM " . self::$table_messages . " m
                 INNER JOIN " . self::$table_threads . " t ON m.thread_id = t.id
                 WHERE m.is_read = 0 AND m.sender_id <> %d
                   AND (t.user1_id = %d OR t.user2_id = %d)",
                $user_id,
                $user_id,
                $user_id
            )
        );

        wp_send_json_success( array( 'unread' => $count ) );
    }

    /**
     * Contato único para usuários comuns: sempre o "financeiro".
     */
    private static function get_contacts_for_finance_target( $user_id, $search = '' ) {
        global $wpdb;

        $finance_user = self::get_finance_user();
        if ( ! $finance_user || (int) $finance_user->ID === (int) $user_id ) {
            return array();
        }

        $payload = self::get_user_payload_from_obj( $finance_user );
        if ( $search && ! self::matches_search( $payload, $search ) ) {
            return array();
        }

        $thread_id = self::maybe_get_or_create_thread( $user_id, (int) $finance_user->ID );

        $unread = 0;
        if ( $thread_id > 0 ) {
            $unread = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(id) FROM " . self::$table_messages . " WHERE thread_id = %d AND sender_id = %d AND is_read = 0",
                    $thread_id,
                    (int) $finance_user->ID
                )
            );
        }

        $payload['thread_id']   = $thread_id;
        $payload['unread']      = $unread;
        $payload['can_start']   = true;
        $payload['can_message'] = true;

        return array( $payload );
    }

    /**
     * Lista contatos quando atuando como financeiro (dashboard).
     */
    private static function get_contacts_for_finance_desk( $finance_id, $search = '' ) {
        global $wpdb;

        if ( ! $finance_id ) {
            return array();
        }

        $threads = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user1_id, user2_id, updated_at
                 FROM " . self::$table_threads . "
                 WHERE user1_id = %d OR user2_id = %d
                 ORDER BY updated_at DESC",
                $finance_id,
                $finance_id
            ),
            ARRAY_A
        );

        $thread_ids = array();
        $contacts   = array();

        foreach ( $threads as $thread ) {
            $other_id = ( (int) $thread['user1_id'] === $finance_id )
                ? (int) $thread['user2_id']
                : (int) $thread['user1_id'];
            $thread_ids[ (int) $thread['id'] ] = $other_id;
        }

        $unread_map = array();
        if ( ! empty( $thread_ids ) ) {
            $ids = implode( ',', array_map( 'intval', array_keys( $thread_ids ) ) );
            $res = $wpdb->get_results(
                "SELECT thread_id, COUNT(id) as unread
                 FROM " . self::$table_messages . "
                 WHERE is_read = 0
                   AND sender_id <> " . (int) $finance_id . "
                   AND thread_id IN ($ids)
                 GROUP BY thread_id",
                ARRAY_A
            );
            foreach ( $res as $r ) {
                $unread_map[ (int) $r['thread_id'] ] = (int) $r['unread'];
            }
        }

        foreach ( $thread_ids as $tid => $other_id ) {
            $payload = self::get_user_payload( $other_id );
            if ( ! $payload ) {
                continue;
            }
            if ( $search && ! self::matches_search( $payload, $search ) ) {
                continue;
            }
            $payload['thread_id']   = (int) $tid;
            $payload['unread']      = isset( $unread_map[ $tid ] ) ? $unread_map[ $tid ] : 0;
            $payload['can_start']   = true;
            $payload['can_message'] = true;
            $contacts[]             = $payload;
        }

        return $contacts;
    }

    /**
     * Usuários que o atual pode iniciar conversa (respeita busca).
     */
    private static function get_startable_users( $user_id, $role, $search ) {
        $args = array(
            'exclude' => array( $user_id ),
            'number'  => 200,
        );

        switch ( $role ) {
            case 'financeiro':
                $args['role__in'] = array( 'financeiro', 'faepa', 'coordenador', 'colaborador' );
                $users        = get_users( $args );
                $finance_user = self::get_finance_user();
                $finance_id   = $finance_user ? (int) $finance_user->ID : 0;
                return array_values( array_filter( $users, function( $u ) use ( $user_id, $finance_id ) {
                    $role = Faepa_Chatbox::get_user_role( $u->ID );
                    if ( ! Faepa_Chatbox::is_chat_role( $role ) ) {
                        return false;
                    }
                    // Financeiro não precisa ver a si mesmo nem o contato financeiro único.
                    if ( (int) $u->ID === (int) $user_id ) {
                        return false;
                    }
                    if ( $finance_id && (int) $u->ID === $finance_id ) {
                        return false;
                    }
                    return true;
                } ) );
            case 'faepa':
                // Financeiro único + coordenadores
                $users = array();
                $finance_user = self::get_finance_user();
                if ( $finance_user && ( '' === $search || self::matches_search( self::get_user_payload_from_obj( $finance_user ), $search ) ) ) {
                    $users[] = $finance_user;
                }
                $args['role__in'] = array( 'coordenador' );
                if ( $search ) {
                    $args['search']         = '*' . $search . '*';
                    $args['search_columns'] = array( 'user_email', 'user_nicename', 'display_name' );
                }
                $coords = get_users( $args );
                return array_merge( $users, $coords );
            case 'coordenador':
                // Financeiro único + colaboradores sob responsabilidade (meta faepa_coordenador_id)
                $users = array();
                $finance_user = self::get_finance_user();
                if ( $finance_user && ( '' === $search || self::matches_search( self::get_user_payload_from_obj( $finance_user ), $search ) ) ) {
                    $users[] = $finance_user;
                }
                $args_colab = array(
                    'role__in'   => array( 'colaborador' ),
                    'number'     => 200,
                    'meta_query' => array(
                        array(
                            'key'   => 'faepa_coordenador_id',
                            'value' => $user_id,
                        ),
                    ),
                );
                if ( $search ) {
                    $args_colab['search']         = '*' . $search . '*';
                    $args_colab['search_columns'] = array( 'user_email', 'user_nicename', 'display_name' );
                }
                $colabs = get_users( $args_colab );
                return array_merge( $users, $colabs );
            case 'colaborador':
                // Colaborador só pode iniciar com o financeiro único.
                $finance_user = self::get_finance_user();
                if ( ! $finance_user ) {
                    return array();
                }
                if ( $search && ! self::matches_search( self::get_user_payload_from_obj( $finance_user ), $search ) ) {
                    return array();
                }
                return array( $finance_user );
            default:
                // Qualquer outra role (ou usuário sem role específica) pode falar com o financeiro.
                $finance_user = self::get_finance_user();
                if ( ! $finance_user || (int) $finance_user->ID === (int) $user_id ) {
                    return array();
                }
                if ( $search && ! self::matches_search( self::get_user_payload_from_obj( $finance_user ), $search ) ) {
                    return array();
                }
                return array( $finance_user );
        }
    }

    /**
     * Cria thread se permitido; retorna ID ou 0.
     */
    private static function maybe_get_or_create_thread( $user_id, $contact_id ) {
        global $wpdb;
        if ( ! $user_id || ! $contact_id ) {
            return 0;
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM " . self::$table_threads . "
                 WHERE (user1_id = %d AND user2_id = %d) OR (user1_id = %d AND user2_id = %d)
                 LIMIT 1",
                $user_id,
                $contact_id,
                $contact_id,
                $user_id
            )
        );
        if ( $existing ) {
            return (int) $existing;
        }

        if ( ! self::can_initiate_with( $user_id, $contact_id ) ) {
            return 0;
        }

        $now = current_time( 'mysql' );
        $wpdb->insert(
            self::$table_threads,
            array(
                'created_at' => $now,
                'updated_at' => $now,
                'user1_id'   => $user_id,
                'user2_id'   => $contact_id,
            ),
            array( '%s', '%s', '%d', '%d' )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Confere se o usuário pertence à thread.
     */
    private static function user_in_thread( $user_id, $thread_id ) {
        global $wpdb;
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM " . self::$table_threads . "
                 WHERE id = %d AND (user1_id = %d OR user2_id = %d)",
                $thread_id,
                $user_id,
                $user_id
            )
        );
        return (bool) $exists;
    }

    /**
     * Retorna o outro participante da thread.
     */
    private static function get_thread_other_user( $thread_id, $user_id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT user1_id, user2_id FROM " . self::$table_threads . " WHERE id = %d LIMIT 1",
                $thread_id
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return 0;
        }
        return ( (int) $row['user1_id'] === $user_id ) ? (int) $row['user2_id'] : (int) $row['user1_id'];
    }

    /**
     * Monta payload básico de usuário.
     */
    private static function get_user_payload( $user_id ) {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return null;
        }
        return self::get_user_payload_from_obj( $user );
    }

    private static function get_user_payload_from_obj( $user ) {
        return array(
            'user_id' => (int) $user->ID,
            'name'    => $user->display_name ?: $user->user_nicename,
            'email'   => $user->user_email,
            'role'    => self::get_user_role( $user->ID ),
        );
    }

    /**
     * Define a role relevante (prioridade: financeiro > faepa > coordenador > colaborador).
     */
    private static function get_user_role( $user_id = 0 ) {
        $user = $user_id ? get_user_by( 'id', $user_id ) : wp_get_current_user();
        if ( ! $user || empty( $user->roles ) ) {
            return '';
        }
        $roles = (array) $user->roles;
        $priority = array( 'financeiro', 'faepa', 'coordenador', 'colaborador' );
        foreach ( $priority as $r ) {
            if ( in_array( $r, $roles, true ) ) {
                return $r;
            }
        }
        // Fallback: trata administradores (ou quem pode gerenciar o site) como "financeiro" para o chat.
        if ( user_can( $user, 'manage_options' ) ) {
            return 'financeiro';
        }
        return $roles[0];
    }

    /**
     * Roles considerados "do chat" (portais).
     */
    public static function is_chat_role( $role ) {
        return in_array( $role, array( 'financeiro', 'faepa', 'coordenador', 'colaborador', 'administrator' ), true );
    }

    /**
     * Retorna o usuário único do financeiro (prioriza role financeiro, fallback admin).
     */
    private static function get_finance_user() {
        if ( null !== self::$finance_user && self::$finance_user instanceof WP_User ) {
            return self::$finance_user;
        }
        self::$finance_user = self::ensure_finance_user();
        return self::$finance_user;
    }

    /**
     * Garante a existência do usuário "Financeiro Colab" (financeiro único).
     */
    private static function ensure_finance_user() {
        $login   = 'financeiro-colab';
        $display = 'Financeiro Colab';
        $email   = 'financeiro-colab@example.com';

        $user = get_user_by( 'login', $login );
        if ( ! $user ) {
            $user = get_user_by( 'email', $email );
        }

        if ( $user ) {
            return $user;
        }

        $role = 'financeiro';
        if ( ! get_role( 'financeiro' ) ) {
            $role = 'administrator';
        }

        $user_id = wp_insert_user( array(
            'user_login'   => $login,
            'user_pass'    => wp_generate_password( 16, true, true ),
            'user_email'   => $email,
            'display_name' => $display,
            'role'         => $role,
        ) );

        if ( is_wp_error( $user_id ) ) {
            return null;
        }

        return get_user_by( 'id', $user_id );
    }

    /**
     * Checa se o usuário pode iniciar conversa com o destino.
     */
    private static function can_initiate_with( $user_id, $target_id ) {
        $role        = self::get_user_role( $user_id );
        $target_role = self::get_user_role( $target_id );

        switch ( $role ) {
            case 'financeiro':
                return true;
            case 'faepa':
                return in_array( $target_role, array( 'financeiro', 'coordenador' ), true );
            case 'coordenador':
                if ( 'financeiro' === $target_role ) {
                    return true;
                }
                if ( 'colaborador' === $target_role ) {
                    $meta = get_user_meta( $target_id, 'faepa_coordenador_id', true );
                    return ( (int) $meta === (int) $user_id );
                }
                return false;
            case 'colaborador':
                return ( 'financeiro' === $target_role );
            default:
                // Demais roles (ou usuários genéricos) podem sempre iniciar com o financeiro.
                if ( 'financeiro' === $target_role ) {
                    return true;
                }
                $finance_user = self::get_finance_user();
                if ( $finance_user && (int) $finance_user->ID === (int) $target_id ) {
                    return true;
                }
                return false;
        }
    }

    /**
     * Verifica se nome/e-mail batem com a busca.
     */
    private static function matches_search( $payload, $search ) {
        $haystack = strtolower( $payload['name'] . ' ' . $payload['email'] );
        return ( false !== strpos( $haystack, strtolower( $search ) ) );
    }
}

// Inicializa o módulo
Faepa_Chatbox::init();
