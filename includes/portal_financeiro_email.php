<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [apf_portal_financeiro_email]
 * Portal para configurar o remetente e visualizar o ultimo e-mail capturado.
 */
if ( ! function_exists( 'apf_render_portal_financeiro_email' ) ) {
    function apf_render_portal_financeiro_email() {
    if ( ! is_user_logged_in() ) {
        $redirect = isset( $_SERVER['REQUEST_URI'] ) ? esc_url( $_SERVER['REQUEST_URI'] ) : home_url();
        return apf_render_login_card( array(
            'redirect'    => $redirect,
            'title'       => 'Portal Financeiro',
            'description' => 'Faça login para configurar o remetente de e-mails.',
        ) );
    }

    $user_id = get_current_user_id();
    if ( ! function_exists( 'apf_user_is_finance' ) || ! apf_user_is_finance( $user_id ) ) {
        return '<div class="apf-fin-mail__restricted">Acesso restrito ao financeiro.</div>';
    }

    if ( class_exists( 'Faepa_Chatbox' ) && function_exists( 'wp_add_inline_script' ) ) {
        wp_add_inline_script(
            Faepa_Chatbox::SCRIPT_HANDLE,
            'window.faepaChatboxPortalReady = true; window.faepaChatboxPortalContext = "financeiro";',
            'before'
        );
    }

    $notice      = '';
    $notice_type = 'success';
    if ( isset( $_GET['apf_fin_mail_notice'] ) ) {
        $notice = sanitize_text_field( wp_unslash( $_GET['apf_fin_mail_notice'] ) );
        if ( isset( $_GET['apf_fin_mail_status'] ) && 'error' === sanitize_text_field( wp_unslash( $_GET['apf_fin_mail_status'] ) ) ) {
            $notice_type = 'error';
        }
    }

    if ( isset( $_POST['apf_fin_mail_test_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_test_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_test_nonce'] ), 'apf_fin_mail_test' ) ) {
            $notice      = 'Não foi possível enviar o e-mail de teste. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $test_to_raw = isset( $_POST['apf_fin_mail_test_to'] ) ? wp_unslash( $_POST['apf_fin_mail_test_to'] ) : '';
            $test_to_raw = is_string( $test_to_raw ) ? trim( $test_to_raw ) : '';
            $test_to     = sanitize_email( $test_to_raw );
            if ( '' !== $test_to_raw && '' === $test_to ) {
                $notice      = 'Informe um e-mail válido para o teste.';
                $notice_type = 'error';
            } elseif ( '' === $test_to ) {
                $notice      = 'Informe um e-mail de destino para o teste.';
                $notice_type = 'error';
            } else {
                $subject = 'FAEPA - Teste de envio';
                $body    = "Este e-mail foi disparado pelo portal financeiro para teste.\n";
                $body   .= 'Data: ' . ( function_exists( 'wp_date' ) ? wp_date( 'd/m/Y H:i' ) : date_i18n( 'd/m/Y H:i' ) ) . "\n";
                $body   .= "Se estiver em localhost, verifique a captura abaixo.\n";

                $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
                wp_mail( $test_to, $subject, $body, $headers );

                if ( function_exists( 'apf_store_mail_capture' ) ) {
                    $from_email = function_exists( 'apf_get_mail_sender_email' ) ? apf_get_mail_sender_email() : '';
                    if ( '' === $from_email ) {
                        $from_email = sanitize_email( get_option( 'admin_email' ) );
                    }
                    $from_name = function_exists( 'apf_get_mail_sender_name' ) ? apf_get_mail_sender_name() : '';
                    apf_store_mail_capture( array(
                        'time'       => current_time( 'Y-m-d H:i:s' ),
                        'to'         => $test_to,
                        'subject'    => $subject,
                        'message'    => $body,
                        'headers'    => implode( "\n", $headers ),
                        'from_email' => $from_email,
                        'from_name'  => $from_name,
                    ) );
                }
                $notice = 'E-mail de teste disparado. Confira a captura abaixo.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_template_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_template_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_template_nonce'] ), 'apf_fin_mail_template' ) ) {
            $notice      = 'Não foi possível salvar a mensagem. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $template_raw = isset( $_POST['apf_fin_mail_template'] ) ? wp_unslash( $_POST['apf_fin_mail_template'] ) : '';
            $template_raw = is_string( $template_raw ) ? $template_raw : '';
            $template     = sanitize_textarea_field( $template_raw );
            $template     = trim( $template );

            if ( '' === $template ) {
                delete_option( 'apf_form_confirmation_template' );
                $notice = 'Mensagem padrão restaurada.';
            } else {
                update_option( 'apf_form_confirmation_template', $template, false );
                $notice = 'Mensagem de confirmação atualizada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_faepa_template_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_faepa_template_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_faepa_template_nonce'] ), 'apf_fin_mail_faepa_template' ) ) {
            $notice      = 'Não foi possível salvar a mensagem da FAEPA. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $template_raw = isset( $_POST['apf_fin_mail_faepa_template'] ) ? wp_unslash( $_POST['apf_fin_mail_faepa_template'] ) : '';
            $template_raw = is_string( $template_raw ) ? $template_raw : '';
            $template     = sanitize_textarea_field( $template_raw );
            $template     = trim( $template );

            if ( '' === $template ) {
                delete_option( 'apf_faepa_payment_email_template' );
                $notice = 'Mensagem padrão da FAEPA restaurada.';
            } else {
                update_option( 'apf_faepa_payment_email_template', $template, false );
                $notice = 'Mensagem da FAEPA atualizada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_access_template_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_access_template_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_access_template_nonce'] ), 'apf_fin_mail_access_template' ) ) {
            $notice      = 'Não foi possível salvar a mensagem de acesso. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $template_raw = isset( $_POST['apf_fin_mail_access_template'] ) ? wp_unslash( $_POST['apf_fin_mail_access_template'] ) : '';
            $template_raw = is_string( $template_raw ) ? $template_raw : '';
            $template     = sanitize_textarea_field( $template_raw );
            $template     = trim( $template );

            if ( '' === $template ) {
                delete_option( 'apf_portal_access_email_template' );
                $notice = 'Mensagem padrão de acesso restaurada.';
            } else {
                update_option( 'apf_portal_access_email_template', $template, false );
                $notice = 'Mensagem de acesso atualizada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_access_capture_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_access_capture_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_access_capture_nonce'] ), 'apf_fin_mail_access_capture' ) ) {
            $notice      = 'Não foi possível salvar a captura de acesso. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $capture_enabled = ! empty( $_POST['apf_fin_mail_access_capture'] );
            if ( $capture_enabled ) {
                update_option( 'apf_mail_capture_access_force', '1', false );
                $notice = 'Captura de acesso ativada.';
            } else {
                delete_option( 'apf_mail_capture_access_force' );
                $notice = 'Captura de acesso desativada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_coord_template_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_coord_template_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_coord_template_nonce'] ), 'apf_fin_mail_coord_template' ) ) {
            $notice      = 'Não foi possível salvar a mensagem ao coordenador. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $template_raw = isset( $_POST['apf_fin_mail_coord_template'] ) ? wp_unslash( $_POST['apf_fin_mail_coord_template'] ) : '';
            $template_raw = is_string( $template_raw ) ? $template_raw : '';
            $template     = sanitize_textarea_field( $template_raw );
            $template     = trim( $template );

            if ( '' === $template ) {
                delete_option( 'apf_coordinator_request_email_template' );
                $notice = 'Mensagem padrão ao coordenador restaurada.';
            } else {
                update_option( 'apf_coordinator_request_email_template', $template, false );
                $notice = 'Mensagem ao coordenador atualizada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_coord_capture_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_coord_capture_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_coord_capture_nonce'] ), 'apf_fin_mail_coord_capture' ) ) {
            $notice      = 'Não foi possível salvar a captura do coordenador. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $capture_enabled = ! empty( $_POST['apf_fin_mail_coord_capture'] );
            if ( $capture_enabled ) {
                update_option( 'apf_mail_capture_coordinator_request_force', '1', false );
                $notice = 'Captura do coordenador ativada.';
            } else {
                delete_option( 'apf_mail_capture_coordinator_request_force' );
                $notice = 'Captura do coordenador desativada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_faepa_forward_template_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_faepa_forward_template_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_faepa_forward_template_nonce'] ), 'apf_fin_mail_faepa_forward_template' ) ) {
            $notice      = 'Não foi possível salvar a mensagem à FAEPA. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $template_raw = isset( $_POST['apf_fin_mail_faepa_forward_template'] ) ? wp_unslash( $_POST['apf_fin_mail_faepa_forward_template'] ) : '';
            $template_raw = is_string( $template_raw ) ? $template_raw : '';
            $template     = sanitize_textarea_field( $template_raw );
            $template     = trim( $template );

            if ( '' === $template ) {
                delete_option( 'apf_faepa_forward_email_template' );
                $notice = 'Mensagem padrão à FAEPA restaurada.';
            } else {
                update_option( 'apf_faepa_forward_email_template', $template, false );
                $notice = 'Mensagem à FAEPA atualizada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_faepa_forward_capture_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_faepa_forward_capture_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_faepa_forward_capture_nonce'] ), 'apf_fin_mail_faepa_forward_capture' ) ) {
            $notice      = 'Não foi possível salvar a captura da FAEPA. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $capture_enabled = ! empty( $_POST['apf_fin_mail_faepa_forward_capture'] );
            if ( $capture_enabled ) {
                update_option( 'apf_mail_capture_faepa_forward_force', '1', false );
                $notice = 'Captura da FAEPA ativada.';
            } else {
                delete_option( 'apf_mail_capture_faepa_forward_force' );
                $notice = 'Captura da FAEPA desativada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_scheduler_template_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_scheduler_template_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_scheduler_template_nonce'] ), 'apf_fin_mail_scheduler_template' ) ) {
            $notice      = 'Não foi possível salvar a mensagem de aviso. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $template_raw = isset( $_POST['apf_fin_mail_scheduler_template'] ) ? wp_unslash( $_POST['apf_fin_mail_scheduler_template'] ) : '';
            $template_raw = is_string( $template_raw ) ? $template_raw : '';
            $template     = sanitize_textarea_field( $template_raw );
            $template     = trim( $template );

            if ( '' === $template ) {
                delete_option( 'apf_scheduler_notice_email_template' );
                $notice = 'Mensagem padrão de aviso restaurada.';
            } else {
                update_option( 'apf_scheduler_notice_email_template', $template, false );
                $notice = 'Mensagem de aviso atualizada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_scheduler_capture_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_scheduler_capture_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_scheduler_capture_nonce'] ), 'apf_fin_mail_scheduler_capture' ) ) {
            $notice      = 'Não foi possível salvar a captura de avisos. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $capture_enabled = ! empty( $_POST['apf_fin_mail_scheduler_capture'] );
            if ( $capture_enabled ) {
                update_option( 'apf_mail_capture_scheduler_force', '1', false );
                $notice = 'Captura de avisos ativada.';
            } else {
                delete_option( 'apf_mail_capture_scheduler_force' );
                $notice = 'Captura de avisos desativada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_faepa_capture_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_faepa_capture_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_faepa_capture_nonce'] ), 'apf_fin_mail_faepa_capture' ) ) {
            $notice      = 'Não foi possível salvar a captura do portal FAEPA. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $capture_enabled = ! empty( $_POST['apf_fin_mail_faepa_capture'] );
            if ( $capture_enabled ) {
                update_option( 'apf_mail_capture_faepa_force', '1', false );
                $notice = 'Captura do portal FAEPA ativada.';
            } else {
                delete_option( 'apf_mail_capture_faepa_force' );
                $notice = 'Captura do portal FAEPA desativada.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_fin_mail_action'] ) ) {
        if ( ! isset( $_POST['apf_fin_mail_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_fin_mail_nonce'] ), 'apf_fin_mail_save' ) ) {
            $notice      = 'Não foi possível salvar o e-mail. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $email_raw = isset( $_POST['apf_fin_mail_email'] ) ? wp_unslash( $_POST['apf_fin_mail_email'] ) : '';
            $email_raw = is_string( $email_raw ) ? trim( $email_raw ) : '';
            $email     = sanitize_email( $email_raw );

            if ( '' !== $email_raw && '' === $email ) {
                $notice      = 'Informe um e-mail válido para o remetente.';
                $notice_type = 'error';
            } elseif ( '' === $email ) {
                delete_option( 'apf_mail_sender_email' );
                $notice = 'E-mail do remetente removido. Será usado o padrão do WordPress.';
            } else {
                update_option( 'apf_mail_sender_email', $email, false );
                $notice = 'E-mail do remetente atualizado.';
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_fin_mail_notice', 'apf_fin_mail_status' ), $target );
        $target = add_query_arg( array(
            'apf_fin_mail_notice' => $notice,
            'apf_fin_mail_status' => ( 'success' === $notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    $custom_email   = function_exists( 'apf_get_mail_sender_email' ) ? apf_get_mail_sender_email() : '';
    $default_email  = sanitize_email( get_option( 'admin_email' ) );
    $effective_email = $custom_email ? $custom_email : $default_email;
    $sender_name    = function_exists( 'apf_get_mail_sender_name' ) ? apf_get_mail_sender_name() : '';
    $user_email     = '';
    $user           = wp_get_current_user();
    if ( $user && ! empty( $user->user_email ) ) {
        $user_email = sanitize_email( $user->user_email );
    }
    $test_default = $user_email ?: ( $effective_email ?: $default_email );
    $template_default = function_exists( 'apf_get_form_confirmation_default_template' )
        ? apf_get_form_confirmation_default_template()
        : '';
    $template_saved = get_option( 'apf_form_confirmation_template', '' );
    $template_saved = is_string( $template_saved ) ? $template_saved : '';
    $template_value = trim( $template_saved ) !== '' ? $template_saved : $template_default;
    $faepa_template_default = function_exists( 'apf_get_faepa_payment_default_template' )
        ? apf_get_faepa_payment_default_template()
        : '';
    $faepa_template_saved = get_option( 'apf_faepa_payment_email_template', '' );
    $faepa_template_saved = is_string( $faepa_template_saved ) ? $faepa_template_saved : '';
    $faepa_template_value = trim( $faepa_template_saved ) !== '' ? $faepa_template_saved : $faepa_template_default;
    $access_template_default = function_exists( 'apf_get_portal_access_default_template' )
        ? apf_get_portal_access_default_template()
        : '';
    $access_template_saved = get_option( 'apf_portal_access_email_template', '' );
    $access_template_saved = is_string( $access_template_saved ) ? $access_template_saved : '';
    $access_template_value = trim( $access_template_saved ) !== '' ? $access_template_saved : $access_template_default;
    $access_capture_force = ! empty( get_option( 'apf_mail_capture_access_force', '' ) );
    $coord_template_default = function_exists( 'apf_get_coordinator_request_default_template' )
        ? apf_get_coordinator_request_default_template()
        : '';
    $coord_template_saved = get_option( 'apf_coordinator_request_email_template', '' );
    $coord_template_saved = is_string( $coord_template_saved ) ? $coord_template_saved : '';
    $coord_template_value = trim( $coord_template_saved ) !== '' ? $coord_template_saved : $coord_template_default;
    $coord_capture_force = ! empty( get_option( 'apf_mail_capture_coordinator_request_force', '' ) );
    $faepa_forward_template_default = function_exists( 'apf_get_faepa_forward_default_template' )
        ? apf_get_faepa_forward_default_template()
        : '';
    $faepa_forward_template_saved = get_option( 'apf_faepa_forward_email_template', '' );
    $faepa_forward_template_saved = is_string( $faepa_forward_template_saved ) ? $faepa_forward_template_saved : '';
    $faepa_forward_template_value = trim( $faepa_forward_template_saved ) !== '' ? $faepa_forward_template_saved : $faepa_forward_template_default;
    $faepa_forward_capture_force = ! empty( get_option( 'apf_mail_capture_faepa_forward_force', '' ) );
    $scheduler_template_default = function_exists( 'apf_get_scheduler_notice_default_template' )
        ? apf_get_scheduler_notice_default_template()
        : '';
    $scheduler_template_saved = get_option( 'apf_scheduler_notice_email_template', '' );
    $scheduler_template_saved = is_string( $scheduler_template_saved ) ? $scheduler_template_saved : '';
    $scheduler_template_value = trim( $scheduler_template_saved ) !== '' ? $scheduler_template_saved : $scheduler_template_default;
    $scheduler_capture_force = ! empty( get_option( 'apf_mail_capture_scheduler_force', '' ) );
    $faepa_capture_force = ! empty( get_option( 'apf_mail_capture_faepa_force', '' ) );

    $capture = get_option( 'apf_mail_capture_last', array() );
    if ( ! is_array( $capture ) ) {
        $capture = array();
    }

    $capture_time    = isset( $capture['time'] ) ? sanitize_text_field( $capture['time'] ) : '';
    $capture_to      = isset( $capture['to'] ) ? sanitize_text_field( $capture['to'] ) : '';
    $capture_subject = isset( $capture['subject'] ) ? sanitize_text_field( $capture['subject'] ) : '';
    $capture_headers = isset( $capture['headers'] ) ? (string) $capture['headers'] : '';
    $capture_message = isset( $capture['message'] ) ? (string) $capture['message'] : '';
    $capture_from    = $effective_email;
    if ( isset( $capture['from_email'] ) && sanitize_email( $capture['from_email'] ) ) {
        $capture_from = sanitize_email( $capture['from_email'] );
    }
    $capture_from_name = $sender_name;
    if ( isset( $capture['from_name'] ) && is_string( $capture['from_name'] ) ) {
        $capture_from_name = sanitize_text_field( $capture['from_name'] );
    }

    $access_capture = get_option( 'apf_mail_capture_access_last', array() );
    if ( ! is_array( $access_capture ) ) {
        $access_capture = array();
    }

    $access_capture_time    = isset( $access_capture['time'] ) ? sanitize_text_field( $access_capture['time'] ) : '';
    $access_capture_to      = isset( $access_capture['to'] ) ? sanitize_text_field( $access_capture['to'] ) : '';
    $access_capture_subject = isset( $access_capture['subject'] ) ? sanitize_text_field( $access_capture['subject'] ) : '';
    $access_capture_headers = isset( $access_capture['headers'] ) ? (string) $access_capture['headers'] : '';
    $access_capture_message = isset( $access_capture['message'] ) ? (string) $access_capture['message'] : '';
    $access_capture_from    = $effective_email;
    if ( isset( $access_capture['from_email'] ) && sanitize_email( $access_capture['from_email'] ) ) {
        $access_capture_from = sanitize_email( $access_capture['from_email'] );
    }
    $access_capture_from_name = $sender_name;
    if ( isset( $access_capture['from_name'] ) && is_string( $access_capture['from_name'] ) ) {
        $access_capture_from_name = sanitize_text_field( $access_capture['from_name'] );
    }

    $coord_capture = get_option( 'apf_mail_capture_coordinator_request_last', array() );
    if ( ! is_array( $coord_capture ) ) {
        $coord_capture = array();
    }

    $coord_capture_time    = isset( $coord_capture['time'] ) ? sanitize_text_field( $coord_capture['time'] ) : '';
    $coord_capture_to      = isset( $coord_capture['to'] ) ? sanitize_text_field( $coord_capture['to'] ) : '';
    $coord_capture_subject = isset( $coord_capture['subject'] ) ? sanitize_text_field( $coord_capture['subject'] ) : '';
    $coord_capture_headers = isset( $coord_capture['headers'] ) ? (string) $coord_capture['headers'] : '';
    $coord_capture_message = isset( $coord_capture['message'] ) ? (string) $coord_capture['message'] : '';
    $coord_capture_from    = $effective_email;
    if ( isset( $coord_capture['from_email'] ) && sanitize_email( $coord_capture['from_email'] ) ) {
        $coord_capture_from = sanitize_email( $coord_capture['from_email'] );
    }
    $coord_capture_from_name = $sender_name;
    if ( isset( $coord_capture['from_name'] ) && is_string( $coord_capture['from_name'] ) ) {
        $coord_capture_from_name = sanitize_text_field( $coord_capture['from_name'] );
    }

    $faepa_forward_capture = get_option( 'apf_mail_capture_faepa_forward_last', array() );
    if ( ! is_array( $faepa_forward_capture ) ) {
        $faepa_forward_capture = array();
    }

    $faepa_forward_capture_time    = isset( $faepa_forward_capture['time'] ) ? sanitize_text_field( $faepa_forward_capture['time'] ) : '';
    $faepa_forward_capture_to      = isset( $faepa_forward_capture['to'] ) ? sanitize_text_field( $faepa_forward_capture['to'] ) : '';
    $faepa_forward_capture_subject = isset( $faepa_forward_capture['subject'] ) ? sanitize_text_field( $faepa_forward_capture['subject'] ) : '';
    $faepa_forward_capture_headers = isset( $faepa_forward_capture['headers'] ) ? (string) $faepa_forward_capture['headers'] : '';
    $faepa_forward_capture_message = isset( $faepa_forward_capture['message'] ) ? (string) $faepa_forward_capture['message'] : '';
    $faepa_forward_capture_from    = $effective_email;
    if ( isset( $faepa_forward_capture['from_email'] ) && sanitize_email( $faepa_forward_capture['from_email'] ) ) {
        $faepa_forward_capture_from = sanitize_email( $faepa_forward_capture['from_email'] );
    }
    $faepa_forward_capture_from_name = $sender_name;
    if ( isset( $faepa_forward_capture['from_name'] ) && is_string( $faepa_forward_capture['from_name'] ) ) {
        $faepa_forward_capture_from_name = sanitize_text_field( $faepa_forward_capture['from_name'] );
    }

    $scheduler_capture = get_option( 'apf_mail_capture_scheduler_last', array() );
    if ( ! is_array( $scheduler_capture ) ) {
        $scheduler_capture = array();
    }

    $scheduler_capture_time    = isset( $scheduler_capture['time'] ) ? sanitize_text_field( $scheduler_capture['time'] ) : '';
    $scheduler_capture_to      = isset( $scheduler_capture['to'] ) ? sanitize_text_field( $scheduler_capture['to'] ) : '';
    $scheduler_capture_subject = isset( $scheduler_capture['subject'] ) ? sanitize_text_field( $scheduler_capture['subject'] ) : '';
    $scheduler_capture_headers = isset( $scheduler_capture['headers'] ) ? (string) $scheduler_capture['headers'] : '';
    $scheduler_capture_message = isset( $scheduler_capture['message'] ) ? (string) $scheduler_capture['message'] : '';
    $scheduler_capture_from    = $effective_email;
    if ( isset( $scheduler_capture['from_email'] ) && sanitize_email( $scheduler_capture['from_email'] ) ) {
        $scheduler_capture_from = sanitize_email( $scheduler_capture['from_email'] );
    }
    $scheduler_capture_from_name = $sender_name;
    if ( isset( $scheduler_capture['from_name'] ) && is_string( $scheduler_capture['from_name'] ) ) {
        $scheduler_capture_from_name = sanitize_text_field( $scheduler_capture['from_name'] );
    }

    ob_start();
    ?>
    <div class="apf-fin-mail" style="font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial">
      <?php if ( '' !== $notice ) : ?>
        <div class="apf-fin-mail__notice apf-fin-mail__notice--<?php echo esc_attr( $notice_type ); ?>">
          <?php echo esc_html( $notice ); ?>
        </div>
      <?php endif; ?>

      <div class="apf-fin-mail__card">
        <h2>Configurar remetente</h2>
        <p>Escolha o e-mail que será usado como remetente dos envios automáticos.</p>

        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_save', 'apf_fin_mail_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_action" value="1">

          <label class="apf-fin-mail__label" for="apf-fin-mail-email">E-mail do remetente</label>
          <input
            class="apf-fin-mail__input"
            id="apf-fin-mail-email"
            type="email"
            name="apf_fin_mail_email"
            placeholder="<?php echo esc_attr( $default_email ?: 'financeiro@exemplo.com' ); ?>"
            value="<?php echo esc_attr( $custom_email ); ?>"
          >

          <p class="apf-fin-mail__hint">
            Se deixar em branco, será usado o remetente padrão do WordPress:
            <strong><?php echo esc_html( $default_email ?: 'não definido' ); ?></strong>.
          </p>

          <div class="apf-fin-mail__current">
            Em uso: <strong><?php echo esc_html( $effective_email ?: '—' ); ?></strong>
          </div>

          <button type="submit" class="apf-fin-mail__btn">Salvar remetente</button>
        </form>
      </div>

      <div class="apf-fin-mail__card apf-fin-mail__card--glossary">
        <h2>Glossário de placeholders</h2>
        <p>Use os códigos abaixo no texto da mensagem.</p>

        <div class="apf-fin-mail__glossary">
          <div class="apf-fin-mail__glossary-group">
            <h3>Placeholders geral</h3>
            <ul>
              <li><code>[nome]</code> Nome do destinatário</li>
              <li><code>[curso]</code> Curso informado</li>
            </ul>
          </div>

          <div class="apf-fin-mail__glossary-group">
            <h3>Placeholders para Mensagem do formulário FAEPA</h3>
            <ul>
              <li><code>[nome]</code> Nome do colaborador</li>
              <li><code>[email]</code> E-mail informado</li>
              <li><code>[telefone]</code> Telefone informado</li>
              <li><code>[cpf]</code> CPF informado</li>
              <li><code>[cnpj]</code> CNPJ informado</li>
              <li><code>[tipo_pessoa]</code> PF ou PJ</li>
              <li><code>[empresa]</code> Nome da empresa</li>
              <li><code>[colaborador]</code> Nome do colaborador/profissional</li>
              <li><code>[numero_controle]</code> Número de controle</li>
              <li><code>[documento_fiscal]</code> Documento fiscal</li>
              <li><code>[diretor]</code> Diretor selecionado</li>
              <li><code>[curso]</code> Nome curto do curso</li>
              <li><code>[valor]</code> Valor informado</li>
              <li><code>[data_servico]</code> Data da prestação</li>
              <li><code>[descricao]</code> Descrição do serviço/material</li>
              <li><code>[classificacao]</code> Classificação</li>
              <li><code>[carga_horaria]</code> Carga horária</li>
              <li><code>[prestacao_contas]</code> Prestação de contas</li>
              <li><code>[banco]</code> Banco</li>
              <li><code>[agencia]</code> Agência</li>
              <li><code>[conta]</code> Conta corrente</li>
            </ul>
          </div>

          <div class="apf-fin-mail__glossary-group">
            <h3>Placeholders para Mensagem de aprovação de acesso</h3>
            <ul>
              <li><code>[nome]</code> Nome do destinatário</li>
              <li><code>[status]</code> Aprovada ou recusada</li>
              <li><code>[portal]</code> Portal do Coordenador ou FAEPA</li>
            </ul>
          </div>

          <div class="apf-fin-mail__glossary-group">
            <h3>Placeholders para Solicitações de colaboradores</h3>
            <ul>
              <li><code>[coordenador]</code> Nome do coordenador</li>
              <li><code>[quantidade]</code> Quantidade de solicitações</li>
              <li><code>[curso]</code> Curso(s) relacionado(s)</li>
              <li><code>[titulo]</code> Assunto da solicitação</li>
              <li><code>[mensagem]</code> Mensagem do financeiro</li>
              <li><code>[portal_url]</code> Link do Portal do Coordenador</li>
            </ul>
          </div>

          <div class="apf-fin-mail__glossary-group">
            <h3>Placeholders para Dados validados pelo financeiro</h3>
            <ul>
              <li><code>[curso]</code> Curso(s) relacionado(s)</li>
              <li><code>[coordenador]</code> Coordenador(es) do retorno</li>
              <li><code>[quantidade]</code> Quantidade de colaboradores</li>
              <li><code>[portal_url]</code> Link do Portal FAEPA</li>
            </ul>
          </div>

          <div class="apf-fin-mail__glossary-group">
            <h3>Placeholders para Mensagem de aviso na agenda</h3>
            <ul>
              <li><code>[nome]</code> Nome do destinatário</li>
              <li><code>[origem]</code> Financeiro da Colab-on ou Coordenador</li>
              <li><code>[titulo]</code> Título do aviso</li>
              <li><code>[data]</code> Data do aviso</li>
            </ul>
          </div>

          <div class="apf-fin-mail__glossary-group">
            <h3>Placeholders para Mensagem para o Portal FAEPA</h3>
            <ul>
              <li><code>[lista]</code> Lista de colaboradores e valores</li>
              <li><code>[curso]</code> Curso informado</li>
              <li><code>[observacao]</code> Observação do envio</li>
            </ul>
          </div>
        </div>
      </div>

      <div class="apf-fin-mail__card apf-fin-mail__card--template">
        <h2>Mensagem do formulário FAEPA</h2>
        <p>Edite o texto da confirmação enviada ao colaborador após o envio do formulário.</p>

        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_template', 'apf_fin_mail_template_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_template_action" value="1">

          <label class="apf-fin-mail__label" for="apf-fin-mail-template">Texto da mensagem</label>
          <textarea
            class="apf-fin-mail__textarea"
            id="apf-fin-mail-template"
            name="apf_fin_mail_template"
            rows="10"
          ><?php echo esc_textarea( $template_value ); ?></textarea>

          <button type="submit" class="apf-fin-mail__btn">Salvar mensagem</button>
        </form>
      </div>

      <div class="apf-fin-mail__card apf-fin-mail__card--capture">
        <h2>Forms FAEPA</h2>
        <p>Captura do forms</p>

        <form method="post" class="apf-fin-mail__test">
          <?php wp_nonce_field( 'apf_fin_mail_test', 'apf_fin_mail_test_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_test_action" value="1">
          <div class="apf-fin-mail__field">
            <label class="apf-fin-mail__label" for="apf-fin-mail-test-to">E-mail de destino</label>
            <input
              class="apf-fin-mail__input"
              id="apf-fin-mail-test-to"
              type="email"
              name="apf_fin_mail_test_to"
              placeholder="destino@exemplo.com"
              value="<?php echo esc_attr( $test_default ); ?>"
              required
            >
          </div>
          <button type="submit" class="apf-fin-mail__btn">Enviar teste</button>
        </form>

        <?php if ( '' === $capture_subject && '' === $capture_message && '' === $capture_to ) : ?>
          <div class="apf-fin-mail__empty">Nenhum e-mail capturado até o momento.</div>
        <?php else : ?>
          <div class="apf-fin-mail__grid">
            <label class="apf-fin-mail__label">Data</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $capture_time ?: '—' ); ?>">

            <label class="apf-fin-mail__label">De</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( trim( $capture_from_name . ' <' . $capture_from . '>' ) ); ?>">

            <label class="apf-fin-mail__label">Para</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $capture_to ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Assunto</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $capture_subject ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Headers</label>
            <textarea class="apf-fin-mail__textarea" rows="4" readonly><?php echo esc_textarea( $capture_headers ); ?></textarea>

            <label class="apf-fin-mail__label">Mensagem</label>
            <textarea class="apf-fin-mail__textarea" rows="8" readonly><?php echo esc_textarea( $capture_message ); ?></textarea>
          </div>
        <?php endif; ?>
      </div>

      <div class="apf-fin-mail__divider" aria-hidden="true"></div>

      <div class="apf-fin-mail__card apf-fin-mail__card--template">
        <h2>Mensagem de aprovação de acesso</h2>
        <p>Texto usado no e-mail enviado quando o financeiro aprova ou recusa acesso ao portal.</p>
        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_access_template', 'apf_fin_mail_access_template_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_access_template_action" value="1">

          <label class="apf-fin-mail__label" for="apf-fin-mail-access-template">Texto da mensagem</label>
          <textarea
            class="apf-fin-mail__textarea"
            id="apf-fin-mail-access-template"
            name="apf_fin_mail_access_template"
            rows="8"
          ><?php echo esc_textarea( $access_template_value ); ?></textarea>

          <p style="margin:8px 0 12px;color:#475467;font-size:12px;">Placeholders: <code>[nome]</code>, <code>[status]</code>, <code>[portal]</code></p>
          <button type="submit" class="apf-fin-mail__btn">Salvar mensagem de acesso</button>
        </form>
      </div>

      <div class="apf-fin-mail__card apf-fin-mail__card--capture">
        <h2>Acesso aos portais</h2>
        <p>Captura do último e-mail de aprovação/recusa de acesso.</p>

        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_access_capture', 'apf_fin_mail_access_capture_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_access_capture_action" value="1">
          <label class="apf-fin-mail__label" style="margin-top:0;">
            <input type="checkbox" name="apf_fin_mail_access_capture" value="1" <?php echo $access_capture_force ? 'checked' : ''; ?>>
            Ativar captura de acesso
          </label>
          <button type="submit" class="apf-fin-mail__btn">Salvar captura</button>
        </form>

        <?php if ( '' === $access_capture_subject && '' === $access_capture_message && '' === $access_capture_to ) : ?>
          <div class="apf-fin-mail__empty">Nenhum e-mail capturado até o momento.</div>
        <?php else : ?>
          <div class="apf-fin-mail__grid">
            <label class="apf-fin-mail__label">Data</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $access_capture_time ?: '—' ); ?>">

            <label class="apf-fin-mail__label">De</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( trim( $access_capture_from_name . ' <' . $access_capture_from . '>' ) ); ?>">

            <label class="apf-fin-mail__label">Para</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $access_capture_to ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Assunto</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $access_capture_subject ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Headers</label>
            <textarea class="apf-fin-mail__textarea" rows="4" readonly><?php echo esc_textarea( $access_capture_headers ); ?></textarea>

            <label class="apf-fin-mail__label">Mensagem</label>
            <textarea class="apf-fin-mail__textarea" rows="8" readonly><?php echo esc_textarea( $access_capture_message ); ?></textarea>
          </div>
        <?php endif; ?>
      </div>

      <div class="apf-fin-mail__divider" aria-hidden="true"></div>

      <div class="apf-fin-mail__card apf-fin-mail__card--template">
        <h2>Solicitações de colaboradores</h2>
        <p>Texto usado no e-mail enviado quando o coordenador recebe novas solicitações.</p>
        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_coord_template', 'apf_fin_mail_coord_template_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_coord_template_action" value="1">

          <label class="apf-fin-mail__label" for="apf-fin-mail-coord-template">Texto da mensagem</label>
          <textarea
            class="apf-fin-mail__textarea"
            id="apf-fin-mail-coord-template"
            name="apf_fin_mail_coord_template"
            rows="8"
          ><?php echo esc_textarea( $coord_template_value ); ?></textarea>

          <p style="margin:8px 0 12px;color:#475467;font-size:12px;">Placeholders: <code>[coordenador]</code>, <code>[quantidade]</code>, <code>[curso]</code>, <code>[titulo]</code>, <code>[mensagem]</code>, <code>[portal_url]</code></p>
          <button type="submit" class="apf-fin-mail__btn">Salvar mensagem do coordenador</button>
        </form>
      </div>

      <div class="apf-fin-mail__card apf-fin-mail__card--capture">
        <h2>Solicitações enviadas</h2>
        <p>Captura do último e-mail enviado ao coordenador.</p>

        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_coord_capture', 'apf_fin_mail_coord_capture_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_coord_capture_action" value="1">
          <label class="apf-fin-mail__label" style="margin-top:0;">
            <input type="checkbox" name="apf_fin_mail_coord_capture" value="1" <?php echo $coord_capture_force ? 'checked' : ''; ?>>
            Ativar captura do coordenador
          </label>
          <button type="submit" class="apf-fin-mail__btn">Salvar captura</button>
        </form>

        <?php if ( '' === $coord_capture_subject && '' === $coord_capture_message && '' === $coord_capture_to ) : ?>
          <div class="apf-fin-mail__empty">Nenhum e-mail capturado até o momento.</div>
        <?php else : ?>
          <div class="apf-fin-mail__grid">
            <label class="apf-fin-mail__label">Data</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $coord_capture_time ?: '—' ); ?>">

            <label class="apf-fin-mail__label">De</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( trim( $coord_capture_from_name . ' <' . $coord_capture_from . '>' ) ); ?>">

            <label class="apf-fin-mail__label">Para</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $coord_capture_to ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Assunto</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $coord_capture_subject ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Headers</label>
            <textarea class="apf-fin-mail__textarea" rows="4" readonly><?php echo esc_textarea( $coord_capture_headers ); ?></textarea>

            <label class="apf-fin-mail__label">Mensagem</label>
            <textarea class="apf-fin-mail__textarea" rows="8" readonly><?php echo esc_textarea( $coord_capture_message ); ?></textarea>
          </div>
        <?php endif; ?>
      </div>

      <div class="apf-fin-mail__divider" aria-hidden="true"></div>

      <div class="apf-fin-mail__card apf-fin-mail__card--template">
        <h2>Dados validados pelo financeiro</h2>
        <p>Texto usado no e-mail enviado à FAEPA quando os dados são validados.</p>
        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_faepa_forward_template', 'apf_fin_mail_faepa_forward_template_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_faepa_forward_template_action" value="1">

          <label class="apf-fin-mail__label" for="apf-fin-mail-faepa-forward-template">Texto da mensagem</label>
          <textarea
            class="apf-fin-mail__textarea"
            id="apf-fin-mail-faepa-forward-template"
            name="apf_fin_mail_faepa_forward_template"
            rows="8"
          ><?php echo esc_textarea( $faepa_forward_template_value ); ?></textarea>

          <p style="margin:8px 0 12px;color:#475467;font-size:12px;">Placeholders: <code>[curso]</code>, <code>[coordenador]</code>, <code>[quantidade]</code>, <code>[portal_url]</code></p>
          <button type="submit" class="apf-fin-mail__btn">Salvar mensagem para a FAEPA</button>
        </form>
      </div>

      <div class="apf-fin-mail__card apf-fin-mail__card--capture">
        <h2>Dados validados enviados</h2>
        <p>Captura do último e-mail enviado à FAEPA.</p>

        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_faepa_forward_capture', 'apf_fin_mail_faepa_forward_capture_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_faepa_forward_capture_action" value="1">
          <label class="apf-fin-mail__label" style="margin-top:0;">
            <input type="checkbox" name="apf_fin_mail_faepa_forward_capture" value="1" <?php echo $faepa_forward_capture_force ? 'checked' : ''; ?>>
            Ativar captura da FAEPA
          </label>
          <button type="submit" class="apf-fin-mail__btn">Salvar captura</button>
        </form>

        <?php if ( '' === $faepa_forward_capture_subject && '' === $faepa_forward_capture_message && '' === $faepa_forward_capture_to ) : ?>
          <div class="apf-fin-mail__empty">Nenhum e-mail capturado até o momento.</div>
        <?php else : ?>
          <div class="apf-fin-mail__grid">
            <label class="apf-fin-mail__label">Data</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $faepa_forward_capture_time ?: '—' ); ?>">

            <label class="apf-fin-mail__label">De</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( trim( $faepa_forward_capture_from_name . ' <' . $faepa_forward_capture_from . '>' ) ); ?>">

            <label class="apf-fin-mail__label">Para</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $faepa_forward_capture_to ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Assunto</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $faepa_forward_capture_subject ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Headers</label>
            <textarea class="apf-fin-mail__textarea" rows="4" readonly><?php echo esc_textarea( $faepa_forward_capture_headers ); ?></textarea>

            <label class="apf-fin-mail__label">Mensagem</label>
            <textarea class="apf-fin-mail__textarea" rows="8" readonly><?php echo esc_textarea( $faepa_forward_capture_message ); ?></textarea>
          </div>
        <?php endif; ?>
      </div>

      <div class="apf-fin-mail__divider" aria-hidden="true"></div>

      <div class="apf-fin-mail__card apf-fin-mail__card--template">
        <h2>Mensagem de aviso na agenda</h2>
        <p>Texto usado no e-mail enviado quando um aviso e adicionado na agenda do colaborador.</p>
        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_scheduler_template', 'apf_fin_mail_scheduler_template_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_scheduler_template_action" value="1">

          <label class="apf-fin-mail__label" for="apf-fin-mail-scheduler-template">Texto da mensagem</label>
          <textarea
            class="apf-fin-mail__textarea"
            id="apf-fin-mail-scheduler-template"
            name="apf_fin_mail_scheduler_template"
            rows="8"
          ><?php echo esc_textarea( $scheduler_template_value ); ?></textarea>

          <p style="margin:8px 0 12px;color:#475467;font-size:12px;">Placeholders: <code>[nome]</code>, <code>[origem]</code>, <code>[titulo]</code>, <code>[data]</code></p>
          <button type="submit" class="apf-fin-mail__btn">Salvar mensagem de aviso</button>
        </form>
      </div>

      <div class="apf-fin-mail__card apf-fin-mail__card--capture">
        <h2>Avisos da agenda</h2>
        <p>Captura do último e-mail de aviso enviado aos colaboradores.</p>

        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_scheduler_capture', 'apf_fin_mail_scheduler_capture_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_scheduler_capture_action" value="1">
          <label class="apf-fin-mail__label" style="margin-top:0;">
            <input type="checkbox" name="apf_fin_mail_scheduler_capture" value="1" <?php echo $scheduler_capture_force ? 'checked' : ''; ?>>
            Ativar captura de avisos
          </label>
          <button type="submit" class="apf-fin-mail__btn">Salvar captura</button>
        </form>

        <?php if ( '' === $scheduler_capture_subject && '' === $scheduler_capture_message && '' === $scheduler_capture_to ) : ?>
          <div class="apf-fin-mail__empty">Nenhum e-mail capturado até o momento.</div>
        <?php else : ?>
          <div class="apf-fin-mail__grid">
            <label class="apf-fin-mail__label">Data</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $scheduler_capture_time ?: '—' ); ?>">

            <label class="apf-fin-mail__label">De</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( trim( $scheduler_capture_from_name . ' <' . $scheduler_capture_from . '>' ) ); ?>">

            <label class="apf-fin-mail__label">Para</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $scheduler_capture_to ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Assunto</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $scheduler_capture_subject ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Headers</label>
            <textarea class="apf-fin-mail__textarea" rows="4" readonly><?php echo esc_textarea( $scheduler_capture_headers ); ?></textarea>

            <label class="apf-fin-mail__label">Mensagem</label>
            <textarea class="apf-fin-mail__textarea" rows="8" readonly><?php echo esc_textarea( $scheduler_capture_message ); ?></textarea>
          </div>
        <?php endif; ?>
      </div>

      <div class="apf-fin-mail__divider" aria-hidden="true"></div>

      <div class="apf-fin-mail__card apf-fin-mail__card--template">
        <h2>Mensagem para o Portal FAEPA</h2>
        <p>Texto usado no e-mail de notificacao quando o pagamento e solicitado pela FAEPA.</p>
        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_faepa_template', 'apf_fin_mail_faepa_template_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_faepa_template_action" value="1">

          <label class="apf-fin-mail__label" for="apf-fin-mail-faepa-template">Texto da mensagem (FAEPA)</label>
          <textarea
            class="apf-fin-mail__textarea"
            id="apf-fin-mail-faepa-template"
            name="apf_fin_mail_faepa_template"
            rows="8"
          ><?php echo esc_textarea( $faepa_template_value ); ?></textarea>

          <p style="margin:8px 0 12px;color:#475467;font-size:12px;">Placeholders: <code>[lista]</code>, <code>[curso]</code>, <code>[observacao]</code></p>
          <button type="submit" class="apf-fin-mail__btn">Salvar mensagem FAEPA</button>
        </form>
      </div>

      <?php
        $faepa_capture = get_option( 'apf_mail_capture_faepa_last', array() );
        $faepa_capture_time = isset( $faepa_capture['time'] ) ? (string) $faepa_capture['time'] : '';
        $faepa_capture_to = isset( $faepa_capture['to'] ) ? (string) $faepa_capture['to'] : '';
        $faepa_capture_subject = isset( $faepa_capture['subject'] ) ? (string) $faepa_capture['subject'] : '';
        $faepa_capture_message = isset( $faepa_capture['message'] ) ? (string) $faepa_capture['message'] : '';
        $faepa_capture_headers = isset( $faepa_capture['headers'] ) ? (string) $faepa_capture['headers'] : '';
        $faepa_capture_from = isset( $faepa_capture['from_email'] ) ? (string) $faepa_capture['from_email'] : '';
        $faepa_capture_from_name = isset( $faepa_capture['from_name'] ) ? (string) $faepa_capture['from_name'] : '';
      ?>
      <div class="apf-fin-mail__card apf-fin-mail__card--capture">
        <h2>Portal FAEPA</h2>
        <p>Captura portal faepa</p>

        <form method="post" class="apf-fin-mail__form">
          <?php wp_nonce_field( 'apf_fin_mail_faepa_capture', 'apf_fin_mail_faepa_capture_nonce' ); ?>
          <input type="hidden" name="apf_fin_mail_faepa_capture_action" value="1">
          <label class="apf-fin-mail__label" style="margin-top:0;">
            <input type="checkbox" name="apf_fin_mail_faepa_capture" value="1" <?php echo $faepa_capture_force ? 'checked' : ''; ?>>
            Ativar captura do portal FAEPA
          </label>
          <button type="submit" class="apf-fin-mail__btn">Salvar captura</button>
        </form>

        <?php if ( '' === $faepa_capture_subject && '' === $faepa_capture_message && '' === $faepa_capture_to ) : ?>
          <div class="apf-fin-mail__empty">Nenhum e-mail capturado até o momento.</div>
        <?php else : ?>
          <div class="apf-fin-mail__grid">
            <label class="apf-fin-mail__label">Data</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $faepa_capture_time ?: '—' ); ?>">

            <label class="apf-fin-mail__label">De</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( trim( $faepa_capture_from_name . ' <' . $faepa_capture_from . '>' ) ); ?>">

            <label class="apf-fin-mail__label">Para</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $faepa_capture_to ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Assunto</label>
            <input class="apf-fin-mail__input" type="text" readonly value="<?php echo esc_attr( $faepa_capture_subject ?: '—' ); ?>">

            <label class="apf-fin-mail__label">Headers</label>
            <textarea class="apf-fin-mail__textarea" rows="4" readonly><?php echo esc_textarea( $faepa_capture_headers ); ?></textarea>

            <label class="apf-fin-mail__label">Mensagem</label>
            <textarea class="apf-fin-mail__textarea" rows="8" readonly><?php echo esc_textarea( $faepa_capture_message ); ?></textarea>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php
    static $apf_fin_mail_css = false;
    if ( ! $apf_fin_mail_css ) :
        $apf_fin_mail_css = true;
    ?>
      <style>
        .apf-fin-mail{max-width:860px;margin:32px auto;padding:0 16px;color:#0f172a}
        .apf-fin-mail__card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px 26px;box-shadow:0 12px 26px rgba(15,23,42,.08);margin-bottom:18px}
        .apf-fin-mail__card h2{margin:0 0 8px;font-size:22px}
        .apf-fin-mail__card p{margin:0 0 18px;color:#475467;font-size:14px}
        .apf-fin-mail__label{display:block;font-weight:700;font-size:13px;margin:12px 0 8px;color:#1e293b}
        .apf-fin-mail__input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:12px 14px;font-size:14px;transition:border-color .18s ease,box-shadow .18s ease}
        .apf-fin-mail__input:focus{border-color:#0f172a;box-shadow:0 0 0 4px rgba(15,23,42,.15);outline:none}
        .apf-fin-mail__textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:12px 14px;font-size:13px;resize:vertical}
        .apf-fin-mail__hint{margin:10px 0 0;font-size:12.5px;color:#64748b}
        .apf-fin-mail__current{margin-top:10px;font-size:12.5px;color:#0f172a;background:#f8fafc;border:1px dashed #cbd5e1;padding:8px 10px;border-radius:10px}
        .apf-fin-mail__btn{margin-top:16px;background:#0f172a;color:#fff;border:none;border-radius:12px;padding:12px 18px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s ease,box-shadow .2s ease}
        .apf-fin-mail__btn:hover{background:#0b1220;box-shadow:0 12px 24px rgba(15,23,42,.2)}
        .apf-fin-mail__test{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:8px 0 16px}
        .apf-fin-mail__test .apf-fin-mail__btn{margin-top:0}
        .apf-fin-mail__field{flex:1 1 240px;min-width:220px}
        .apf-fin-mail__notice{margin-bottom:16px;border-radius:12px;padding:12px 14px;font-size:13px}
        .apf-fin-mail__notice--success{background:#ecfdf3;border:1px solid #a7f3d0;color:#047857}
        .apf-fin-mail__notice--error{background:#fff1f2;border:1px solid #fecdd3;color:#be123c}
        .apf-fin-mail__restricted{max-width:720px;margin:24px auto;padding:12px 16px;border-radius:12px;border:1px solid #fecdd3;background:#fff1f2;color:#9f1239}
        .apf-fin-mail__empty{border:1px dashed #cbd5e1;border-radius:12px;padding:16px;color:#64748b;background:#f8fafc}
        .apf-fin-mail__glossary{padding:12px 14px;border-radius:12px;border:1px dashed #cbd5e1;background:#f8fafc}
        .apf-fin-mail__glossary-group + .apf-fin-mail__glossary-group{margin-top:14px;padding-top:12px;border-top:1px dashed #cbd5e1}
        .apf-fin-mail__glossary-group h3{margin:0 0 8px;font-size:14px;color:#0f172a}
        .apf-fin-mail__divider{height:4px;background:#000;border-radius:999px;margin:10px 0 18px}
        .apf-fin-mail__glossary ul{margin:0;padding-left:18px;color:#475467;font-size:13px;line-height:1.45}
        .apf-fin-mail__glossary code{background:#e2e8f0;border-radius:6px;padding:2px 6px;font-size:12px}
      </style>
    <?php
    endif;

        return ob_get_clean();
    }
}

add_shortcode( 'apf_portal_financeiro_email', 'apf_render_portal_financeiro_email' );
