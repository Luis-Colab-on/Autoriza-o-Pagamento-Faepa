<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'apf_faepa_append_notice' ) ) {
    /**
     * Junta mensagens de aviso mantendo espaçamento consistente.
     *
     * @param string $notice
     * @param string $extra
     * @return string
     */
    function apf_faepa_append_notice( $notice, $extra ) {
        $notice = is_string( $notice ) ? trim( $notice ) : '';
        $extra  = is_string( $extra ) ? trim( $extra ) : '';
        if ( '' === $extra ) {
            return $notice;
        }
        if ( '' === $notice ) {
            return $extra;
        }
        return $notice . ' ' . $extra;
    }
}

if ( ! function_exists( 'apf_faepa_find_batch_id_for_request' ) ) {
    /**
     * Encontra o batch_id de uma solicitacao.
     *
     * @param string $request_id
     * @return string
     */
    function apf_faepa_find_batch_id_for_request( $request_id ) {
        $request_id = sanitize_text_field( (string) $request_id );
        if ( '' === $request_id || ! function_exists( 'apf_get_coordinator_requests' ) ) {
            return '';
        }
        $requests = apf_get_coordinator_requests();
        if ( ! is_array( $requests ) ) {
            return '';
        }
        foreach ( $requests as $request ) {
            if ( ! is_array( $request ) || ! isset( $request['id'], $request['batch_id'] ) ) {
                continue;
            }
            $current_id = sanitize_text_field( (string) $request['id'] );
            if ( '' !== $current_id && $current_id === $request_id ) {
                return sanitize_text_field( (string) $request['batch_id'] );
            }
        }
        return '';
    }
}

if ( ! function_exists( 'apf_faepa_get_batch_counts' ) ) {
    /**
     * Retorna totais de aprovados/pagos/notificados para um lote.
     *
     * @param string $batch_id
     * @return array<string,int>
     */
    function apf_faepa_get_batch_counts( $batch_id ) {
        $batch_id = sanitize_text_field( (string) $batch_id );
        $counts = array(
            'approved' => 0,
            'paid'     => 0,
            'notified' => 0,
        );
        if ( '' === $batch_id || ! function_exists( 'apf_get_coordinator_requests' ) ) {
            return $counts;
        }
        $requests = apf_get_coordinator_requests();
        if ( ! is_array( $requests ) ) {
            return $counts;
        }
        foreach ( $requests as $request ) {
            if ( ! is_array( $request ) || ! isset( $request['batch_id'] ) ) {
                continue;
            }
            if ( $request['batch_id'] !== $batch_id ) {
                continue;
            }
            $status = isset( $request['status'] ) ? sanitize_key( $request['status'] ) : 'pending';
            if ( 'approved' !== $status ) {
                continue;
            }
            $counts['approved']++;
            if ( ! empty( $request['faepa_paid'] ) ) {
                $counts['paid']++;
            }
            if ( ! empty( $request['faepa_payment_notified'] ) ) {
                $counts['notified']++;
            }
        }
        return $counts;
    }
}

if ( ! function_exists( 'apf_faepa_should_autonotify_batch' ) ) {
    /**
     * Define se um lote pode ser notificado automaticamente.
     *
     * @param string $batch_id
     * @return bool
     */
    function apf_faepa_should_autonotify_batch( $batch_id ) {
        $counts = apf_faepa_get_batch_counts( $batch_id );
        return ( $counts['approved'] > 0 && $counts['paid'] >= $counts['approved'] && $counts['notified'] < $counts['approved'] );
    }
}

if ( ! function_exists( 'apf_faepa_send_payment_notifications' ) ) {
    /**
     * Dispara notificacoes de pagamento para um lote.
     *
     * @param string $batch_id
     * @param string $custom_note
     * @return array<string,mixed>
     */
    function apf_faepa_send_payment_notifications( $batch_id, $custom_note = '' ) {
        $result = array(
            'notice'      => '',
            'notice_type' => 'error',
            'mail_sent'   => 0,
            'portal_targets' => 0,
        );
        $batch_id    = sanitize_text_field( (string) $batch_id );
        $custom_note = sanitize_textarea_field( (string) $custom_note );

        if ( '' === $batch_id ) {
            $result['notice'] = 'Lote invalido. Recarregue a pagina e tente novamente.';
            return $result;
        }
        if ( ! function_exists( 'apf_get_coordinator_requests' ) ) {
            $result['notice'] = 'Nao foi possivel carregar as solicitacoes.';
            return $result;
        }

        $requests = apf_get_coordinator_requests();
        $batch_entries = array();
        if ( is_array( $requests ) ) {
            foreach ( $requests as $entry ) {
                if ( ! is_array( $entry ) || ! isset( $entry['batch_id'] ) || $entry['batch_id'] !== $batch_id ) {
                    continue;
                }
                if ( isset( $entry['status'] ) && 'approved' !== sanitize_key( $entry['status'] ) ) {
                    continue;
                }
                $batch_entries[] = $entry;
            }
        }

        if ( empty( $batch_entries ) ) {
            $result['notice'] = 'Nenhum colaborador disponivel para notificar neste lote.';
            return $result;
        }
        $unpaid = array_filter( $batch_entries, function( $entry ) {
            return empty( $entry['faepa_paid'] );
        } );
        if ( ! empty( $unpaid ) ) {
            $result['notice'] = 'Confirme todos os pagamentos antes de enviar as notificacoes.';
            return $result;
        }

        $finance_email = sanitize_email( get_option( 'admin_email' ) );
        $coordinator_email = '';
        $coordinator_name  = '';
        $course_label      = '';
        if ( ! empty( $batch_entries[0]['coordinator_email'] ) ) {
            $coordinator_email = sanitize_email( (string) $batch_entries[0]['coordinator_email'] );
        }
        if ( ! empty( $batch_entries[0]['coordinator_name'] ) ) {
            $coordinator_name = sanitize_text_field( (string) $batch_entries[0]['coordinator_name'] );
        }
        if ( ! empty( $batch_entries[0]['course'] ) ) {
            $course_label = sanitize_text_field( (string) $batch_entries[0]['course'] );
        }

        $lines = array();
        $providers_payload = array();
        foreach ( $batch_entries as $entry ) {
            $name  = isset( $entry['provider_name'] ) ? sanitize_text_field( (string) $entry['provider_name'] ) : '';
            $value = isset( $entry['provider_value'] ) ? sanitize_text_field( (string) $entry['provider_value'] ) : '';
            $lines[] = '- ' . ( $name ?: 'Colaborador' ) . ( $value ? ' — ' . $value : '' );

            $email = isset( $entry['provider_email'] ) ? sanitize_email( (string) $entry['provider_email'] ) : '';
            if ( $email ) {
                if ( ! isset( $providers_payload[ $email ] ) ) {
                    $providers_payload[ $email ] = array(
                        'name'   => $name ?: $email,
                        'values' => array(),
                    );
                }
                if ( $value ) {
                    $providers_payload[ $email ]['values'][] = $value;
                }
            }
        }

        $subject = 'FAEPA aprovou e solicitou o pagamento - ' . ( $course_label ?: 'FAEPA' );
        $observacao = $custom_note ? 'Observacao: ' . $custom_note : '';
        $base_message = apf_replace_placeholders( apf_get_faepa_payment_template(), array(
            '[lista]'      => implode( "\n", $lines ),
            '[curso]'      => $course_label ?: 'FAEPA',
            '[observacao]' => $observacao,
        ) );
        $base_message = trim( (string) $base_message );

        $portal_targets = 0;
        $portal_error   = '';

        $portal_recipients = array();
        $director_key = '';
        if ( function_exists( 'apf_inbox_build_director_key' ) ) {
            $director_key = apf_inbox_build_director_key( $coordinator_name, $course_label );
        }
        if ( $coordinator_email ) {
            $portal_recipients[] = array(
                'user_id'      => isset( $batch_entries[0]['coordinator_user_id'] ) ? (int) $batch_entries[0]['coordinator_user_id'] : 0,
                'name'         => $coordinator_name ?: $coordinator_email,
                'email'        => $coordinator_email,
                'group'        => 'coordinators',
                'director_key' => $director_key,
                'director_name'=> $coordinator_name,
                'course'       => $course_label,
            );
        }
        if ( ! empty( $providers_payload ) ) {
            foreach ( $providers_payload as $email => $payload ) {
                $portal_recipients[] = array(
                    'user_id'      => 0,
                    'name'         => $payload['name'] ?: $email,
                    'email'        => $email,
                    'group'        => 'providers',
                    'director_key' => $director_key,
                    'director_name'=> $coordinator_name,
                    'course'       => $course_label,
                );
            }
        }
        if ( ! empty( $portal_recipients ) && function_exists( 'apf_scheduler_get_events' ) && function_exists( 'apf_scheduler_store_events' ) ) {
            $existing_events = apf_scheduler_get_events();
            $event_id = 'faepa_pay_' . preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', $batch_id );
            if ( '' === $event_id ) {
                $event_id = 'faepa_pay_' . uniqid();
            }
            $event_date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : date_i18n( 'Y-m-d' );
            $event_recipients = array();
            $existing_index   = null;

            foreach ( $existing_events as $idx => $evt ) {
                $current_id = isset( $evt['id'] ) ? sanitize_text_field( (string) $evt['id'] ) : '';
                if ( '' !== $current_id && $current_id === $event_id ) {
                    $existing_index = $idx;
                    if ( ! empty( $evt['recipients'] ) && is_array( $evt['recipients'] ) ) {
                        $event_recipients = $evt['recipients'];
                    }
                    break;
                }
            }

            foreach ( $portal_recipients as $recipient ) {
                if ( ! is_array( $recipient ) ) {
                    continue;
                }
                $group   = isset( $recipient['group'] ) ? sanitize_key( $recipient['group'] ) : 'providers';
                if ( ! in_array( $group, array( 'providers', 'coordinators' ), true ) ) {
                    $group = 'providers';
                }
                $email   = isset( $recipient['email'] ) ? sanitize_email( $recipient['email'] ) : '';
                $user_id = isset( $recipient['user_id'] ) ? (int) $recipient['user_id'] : 0;
                if ( '' === $email && $user_id <= 0 ) {
                    continue;
                }
                $name     = isset( $recipient['name'] ) ? sanitize_text_field( $recipient['name'] ) : '';
                $dir_key  = isset( $recipient['director_key'] ) ? sanitize_text_field( $recipient['director_key'] ) : '';
                $dir_name = isset( $recipient['director_name'] ) ? sanitize_text_field( $recipient['director_name'] ) : '';
                $course   = isset( $recipient['course'] ) ? sanitize_text_field( $recipient['course'] ) : '';

                $key = '';
                if ( function_exists( 'apf_scheduler_make_recipient_key' ) ) {
                    $key = apf_scheduler_make_recipient_key( $user_id, $email, $group );
                }
                if ( '' === $key ) {
                    $key = $email
                        ? 'email_' . strtolower( $email ) . ( 'coordinators' === $group ? '_coordinators' : '_providers' )
                        : 'user_' . $user_id;
                }

                $event_recipients[] = array(
                    'key'          => $key,
                    'user_id'      => $user_id,
                    'name'         => $name ?: ( $email ?: $key ),
                    'email'        => $email,
                    'group'        => $group,
                    'director_key' => $dir_key,
                    'director_name'=> $dir_name,
                    'course'       => $course,
                );
            }

            if ( ! empty( $event_recipients ) ) {
                $unique = array();
                $seen   = array();
                foreach ( $event_recipients as $recipient ) {
                    if ( ! is_array( $recipient ) ) {
                        continue;
                    }
                    $rec_key = isset( $recipient['key'] ) ? (string) $recipient['key'] : '';
                    if ( '' === $rec_key ) {
                        continue;
                    }
                    $rec_key = strtolower( $rec_key );
                    if ( isset( $seen[ $rec_key ] ) ) {
                        continue;
                    }
                    $seen[ $rec_key ] = true;
                    $unique[] = $recipient;
                }

                $entry = array(
                    'id'         => $event_id,
                    'date'       => $event_date,
                    'title'      => $subject,
                    'message'    => $base_message,
                    'recipients' => $unique,
                    'created_by' => get_current_user_id(),
                    'created_at' => time(),
                );

                if ( null !== $existing_index ) {
                    $preserved = $existing_events[ $existing_index ];
                    if ( isset( $preserved['created_at'] ) ) {
                        $entry['created_at'] = (int) $preserved['created_at'];
                    }
                    if ( isset( $preserved['created_by'] ) ) {
                        $entry['created_by'] = (int) $preserved['created_by'];
                    }
                    $existing_events[ $existing_index ] = array_merge( $preserved, $entry );
                } else {
                    $existing_events[] = $entry;
                }

                apf_scheduler_store_events( $existing_events );
                $portal_targets = count( $unique );
            } else {
                $portal_error = 'Nenhum destinatario valido para registrar no portal.';
            }
        } elseif ( empty( $portal_recipients ) ) {
            $portal_error = 'Nenhum destinatario disponivel para registrar no portal.';
        }

        $mail_errors = array();
        $mail_sent   = 0;
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
        $capture_enabled = function_exists( 'apf_mail_capture_enabled' ) ? apf_mail_capture_enabled() : false;
        if ( ! $capture_enabled ) {
            $capture_enabled = ! empty( get_option( 'apf_mail_capture_faepa_force', '' ) );
        }
        if ( $capture_enabled && function_exists( 'apf_store_mail_capture_faepa' ) ) {
            $capture_recipients = array();
            if ( $finance_email ) {
                $capture_recipients[] = $finance_email;
            }
            if ( $coordinator_email ) {
                $capture_recipients[] = $coordinator_email;
            }
            if ( ! empty( $providers_payload ) ) {
                $capture_recipients = array_merge( $capture_recipients, array_keys( $providers_payload ) );
            }
            $capture_recipients = array_unique( array_filter( array_map( 'sanitize_email', $capture_recipients ) ) );
            $from_email = function_exists( 'apf_get_mail_sender_email' ) ? apf_get_mail_sender_email() : '';
            if ( '' === $from_email ) {
                $from_email = sanitize_email( get_option( 'admin_email' ) );
            }
            $from_name = function_exists( 'apf_get_mail_sender_name' ) ? apf_get_mail_sender_name() : '';
            apf_store_mail_capture_faepa( array(
                'time'       => current_time( 'Y-m-d H:i:s' ),
                'to'         => implode( ', ', $capture_recipients ),
                'subject'    => $subject,
                'message'    => $base_message,
                'headers'    => implode( "\n", $headers ),
                'from_email' => $from_email,
                'from_name'  => $from_name,
            ) );
        }
        if ( $finance_email ) {
            if ( wp_mail( $finance_email, $subject, $base_message, $headers ) ) {
                $mail_sent++;
            } else {
                $mail_errors[] = 'financeiro';
            }
        }
        if ( $coordinator_email ) {
            $body = $base_message;
            if ( $coordinator_name ) {
                $body = 'Ola ' . $coordinator_name . ",\n\n" . $body;
            }
            if ( wp_mail( $coordinator_email, $subject, $body, $headers ) ) {
                $mail_sent++;
            } else {
                $mail_errors[] = 'coordenador';
            }
        }
        if ( ! empty( $providers_payload ) ) {
            foreach ( $providers_payload as $email => $payload ) {
                $body = 'Ola ' . ( $payload['name'] ?: $email ) . ",\n\n";
                $body .= "A FAEPA aprovou e solicitou seu pagamento; ele deve cair nos proximos dias.\n";
                if ( ! empty( $payload['values'] ) ) {
                    $body .= 'Valor(es): ' . implode( ', ', $payload['values'] ) . "\n";
                }
                $body .= "\n" . $base_message;
                if ( wp_mail( $email, $subject, $body, $headers ) ) {
                    $mail_sent++;
                } else {
                    $mail_errors[] = 'colaborador ' . $email;
                }
            }
        }

        $total_notifications = $mail_sent + $portal_targets;
        $result['mail_sent'] = $mail_sent;
        $result['portal_targets'] = $portal_targets;

        if ( $total_notifications > 0 ) {
            $timestamp = time();
            foreach ( $requests as $idx => $entry ) {
                if ( ! is_array( $entry ) || ! isset( $entry['batch_id'] ) || $entry['batch_id'] !== $batch_id ) {
                    continue;
                }
                if ( isset( $entry['status'] ) && 'approved' !== sanitize_key( $entry['status'] ) ) {
                    continue;
                }
                $requests[ $idx ]['faepa_payment_notified']    = true;
                $requests[ $idx ]['faepa_payment_notified_at'] = $timestamp;
                if ( '' !== $custom_note ) {
                    $requests[ $idx ]['faepa_payment_notify_note'] = $custom_note;
                }
            }
            apf_store_coordinator_requests( $requests );
            if ( $portal_targets > 0 && $mail_sent === 0 ) {
                $result['notice']      = 'Notificacoes registradas nos portais dos destinatarios.';
                $result['notice_type'] = 'success';
            } elseif ( $portal_targets > 0 && ! empty( $mail_errors ) ) {
                $result['notice']      = 'Notificacoes registradas nos portais; e-mails falharam para: ' . implode( ', ', $mail_errors );
                $result['notice_type'] = 'success';
            } elseif ( $portal_targets > 0 && empty( $mail_errors ) ) {
                $result['notice']      = 'Notificacoes registradas nos portais e enviadas por e-mail.';
                $result['notice_type'] = 'success';
            } elseif ( empty( $mail_errors ) ) {
                $result['notice']      = 'Notificacoes enviadas com sucesso.';
                $result['notice_type'] = 'success';
            } else {
                $result['notice']      = 'Notificacoes enviadas, mas falharam para: ' . implode( ', ', $mail_errors );
                $result['notice_type'] = 'success';
            }
        } else {
            $result['notice'] = $portal_error
                ? 'Nao foi possivel registrar notificacoes: ' . $portal_error
                : 'Nao foi possivel enviar notificacoes. Verifique a configuracao de e-mail do site.';
            $result['notice_type'] = 'error';
        }

        return $result;
    }
}

if ( ! function_exists( 'apf_faepa_try_autonotify_from_request' ) ) {
    /**
     * Tenta disparar notificacoes automaticas quando um item e aprovado.
     *
     * @param string $request_id
     * @param string $notice
     * @param string $notice_type
     * @return array{notice:string,notice_type:string,triggered:bool}
     */
    function apf_faepa_try_autonotify_from_request( $request_id, $notice, $notice_type ) {
        $batch_id = apf_faepa_find_batch_id_for_request( $request_id );
        if ( '' === $batch_id || ! apf_faepa_should_autonotify_batch( $batch_id ) ) {
            return array(
                'notice'      => $notice,
                'notice_type' => $notice_type,
                'triggered'   => false,
            );
        }

        $result = apf_faepa_send_payment_notifications( $batch_id, '' );
        if ( 'error' === $result['notice_type'] ) {
            return array(
                'notice'      => $result['notice'],
                'notice_type' => 'error',
                'triggered'   => true,
            );
        }

        $notice = apf_faepa_append_notice( $notice, 'Notificacoes de pagamento enviadas.' );
        return array(
            'notice'      => $notice,
            'notice_type' => $notice_type,
            'triggered'   => true,
        );
    }
}

if ( ! function_exists( 'apf_render_portal_faepa' ) ) {
    function apf_render_portal_faepa() {
    if ( ! is_user_logged_in() ) {
        $redirect = isset( $_SERVER['REQUEST_URI'] ) ? esc_url( $_SERVER['REQUEST_URI'] ) : home_url();
        return apf_render_login_card( array(
            'redirect'    => $redirect,
            'title'       => 'Portal FAEPA',
            'description' => 'Faça login para ver todos os avisos programados para colaboradores e coordenadores.',
        ) );
    }

    if ( class_exists( 'Faepa_Chatbox' ) && function_exists( 'wp_add_inline_script' ) ) {
        wp_add_inline_script(
            Faepa_Chatbox::SCRIPT_HANDLE,
            'window.faepaChatboxPortalReady = true; window.faepaChatboxPortalContext = "faepa";',
            'before'
        );
    }

    $faepa_notice      = '';
    $faepa_notice_type = 'success';

    // Aprovação rápida sem anexo/mensagem
    if ( isset( $_POST['apf_faepa_approve_action'] ) ) {
        $is_ajax = ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] );

        if ( ! isset( $_POST['apf_faepa_approve_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_faepa_approve_nonce'] ), 'apf_faepa_approve' ) ) {
            $faepa_notice      = 'Não foi possível aprovar o pagamento. Recarregue a página e tente novamente.';
            $faepa_notice_type = 'error';
        } else {
            $request_id = isset( $_POST['apf_faepa_request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_faepa_request_id'] ) ) : '';
            if ( '' === $request_id ) {
                $faepa_notice      = 'Seleção inválida. Recarregue a página e tente novamente.';
                $faepa_notice_type = 'error';
            } else {
                $approved = apf_mark_coordinator_request_paid( $request_id, array(
                    'user_id' => get_current_user_id(),
                    'note'    => '',
                    'attachment_id' => 0,
                ) );
                if ( $approved ) {
                    $faepa_notice      = 'Pagamento aprovado.';
                    $faepa_notice_type = 'success';
                    $auto_result = apf_faepa_try_autonotify_from_request( $request_id, $faepa_notice, $faepa_notice_type );
                    $faepa_notice = $auto_result['notice'];
                    $faepa_notice_type = $auto_result['notice_type'];
                } else {
                    $faepa_notice      = 'Não foi possível aprovar este pagamento.';
                    $faepa_notice_type = 'error';
                }
            }
        }

        if ( $is_ajax ) {
            if ( 'success' === $faepa_notice_type ) {
                wp_send_json_success( array( 'message' => $faepa_notice ) );
            } else {
                wp_send_json_error( array( 'message' => $faepa_notice ) );
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_faepa_pay_notice', 'apf_faepa_pay_status' ), $target );
        $target = add_query_arg( array(
            'apf_faepa_pay_notice' => $faepa_notice,
            'apf_faepa_pay_status' => ( 'success' === $faepa_notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_POST['apf_faepa_pay_action'] ) ) {
        if ( ! isset( $_POST['apf_faepa_pay_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_faepa_pay_nonce'] ), 'apf_faepa_pay' ) ) {
            $faepa_notice      = 'Não foi possível confirmar o pagamento. Recarregue a página e tente novamente.';
            $faepa_notice_type = 'error';
        } else {
            $request_id = isset( $_POST['apf_faepa_request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_faepa_request_id'] ) ) : '';
            $note       = isset( $_POST['apf_faepa_pay_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['apf_faepa_pay_note'] ) ) : '';
            $attachment_id = 0;
            $upload_error  = '';
            if ( ! empty( $_FILES['apf_faepa_pay_file']['name'] ) ) {
                if ( ! function_exists( 'media_handle_upload' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }
                $attachment_id = media_handle_upload( 'apf_faepa_pay_file', 0 );
                if ( is_wp_error( $attachment_id ) ) {
                    $upload_error = $attachment_id->get_error_message();
                    $attachment_id = 0;
                }
            }
            if ( '' === $request_id ) {
                $faepa_notice      = 'Seleção inválida. Recarregue a página e tente novamente.';
                $faepa_notice_type = 'error';
            } elseif ( '' === $note && $attachment_id <= 0 ) {
                $faepa_notice      = 'Informe uma mensagem ou anexe o comprovante antes de confirmar.';
                $faepa_notice_type = 'error';
            } elseif ( '' !== $upload_error ) {
                $faepa_notice      = 'Erro ao enviar o anexo: ' . $upload_error;
                $faepa_notice_type = 'error';
            } else {
                $updated = apf_mark_coordinator_request_paid( $request_id, array(
                    'user_id' => get_current_user_id(),
                    'note'    => $note,
                    'attachment_id' => $attachment_id,
                ) );
                if ( $updated ) {
                    $faepa_notice      = 'Solicitação marcada como aprovada.';
                    $faepa_notice_type = 'success';
                    $auto_result = apf_faepa_try_autonotify_from_request( $request_id, $faepa_notice, $faepa_notice_type );
                    $faepa_notice = $auto_result['notice'];
                    $faepa_notice_type = $auto_result['notice_type'];
                } else {
                    $faepa_notice      = 'Não foi possível confirmar este pagamento.';
                    $faepa_notice_type = 'error';
                }
            }
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_faepa_pay_notice', 'apf_faepa_pay_status' ), $target );
        $target = add_query_arg( array(
            'apf_faepa_pay_notice' => $faepa_notice,
            'apf_faepa_pay_status' => ( 'success' === $faepa_notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( isset( $_GET['apf_faepa_pay_notice'] ) ) {
        $faepa_notice = sanitize_text_field( wp_unslash( $_GET['apf_faepa_pay_notice'] ) );
        if ( isset( $_GET['apf_faepa_pay_status'] ) && 'error' === sanitize_text_field( wp_unslash( $_GET['apf_faepa_pay_status'] ) ) ) {
            $faepa_notice_type = 'error';
        }
    }

    if ( isset( $_POST['apf_faepa_notify_action'] ) ) {
        if ( ! isset( $_POST['apf_faepa_notify_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['apf_faepa_notify_nonce'] ), 'apf_faepa_notify' ) ) {
            $faepa_notice      = 'Não foi possível enviar as notificações. Recarregue a página e tente novamente.';
            $faepa_notice_type = 'error';
        } else {
            $batch_id = isset( $_POST['apf_faepa_batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_faepa_batch_id'] ) ) : '';
            $custom_note = isset( $_POST['apf_faepa_notify_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['apf_faepa_notify_note'] ) ) : '';
            $result = apf_faepa_send_payment_notifications( $batch_id, $custom_note );
            $faepa_notice = $result['notice'];
            $faepa_notice_type = $result['notice_type'];
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }
        $target = remove_query_arg( array( 'apf_faepa_pay_notice', 'apf_faepa_pay_status' ), $target );
        $target = add_query_arg( array(
            'apf_faepa_pay_notice' => $faepa_notice,
            'apf_faepa_pay_status' => ( 'success' === $faepa_notice_type ) ? 'success' : 'error',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    if ( ! function_exists( 'apf_scheduler_get_events' ) ) {
        return '<div style="max-width:720px;margin:16px auto;padding:12px;border:1px solid #fee4e2;border-radius:12px;background:#fff4ed;color:#b42318">O calendário financeiro não está disponível no momento.</div>';
    }

    $raw_events           = apf_scheduler_get_events();
    $calendar_events_map  = array();

    foreach ( $raw_events as $event ) {
        if ( ! is_array( $event ) ) {
            continue;
        }
        $date = isset( $event['date'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $event['date'] ) : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            continue;
        }
        $event_id = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';
        if ( '' !== $event_id && strpos( $event_id, 'faepa_pay_' ) === 0 ) {
            // Avisos automáticos de pagamento da FAEPA não precisam aparecer neste calendário.
            continue;
        }
        $title = isset( $event['title'] ) ? sanitize_text_field( $event['title'] ) : '';
        if ( '' === $title ) {
            continue;
        }

        $recipients = array(
            'providers'    => array(),
            'coordinators' => array(),
        );
        $entry_groups = array();

        if ( ! empty( $event['recipients'] ) && is_array( $event['recipients'] ) ) {
            foreach ( $event['recipients'] as $recipient ) {
                if ( ! is_array( $recipient ) ) {
                    continue;
                }
                $group = isset( $recipient['group'] ) ? sanitize_key( $recipient['group'] ) : '';
                if ( ! in_array( $group, array( 'providers', 'coordinators' ), true ) ) {
                    $group = 'providers';
                }

                $name  = isset( $recipient['name'] ) ? sanitize_text_field( $recipient['name'] ) : '';
                $email = isset( $recipient['email'] ) ? sanitize_email( $recipient['email'] ) : '';
                if ( '' === $name && '' === $email ) {
                    continue;
                }

                $display = $name ?: $email;
                if ( $display && $email && false === stripos( $display, $email ) ) {
                    $display .= ' <' . $email . '>';
                }

                $director = isset( $recipient['director_name'] ) ? sanitize_text_field( $recipient['director_name'] ) : '';
                $course   = isset( $recipient['course'] ) ? sanitize_text_field( $recipient['course'] ) : '';

                $recipients[ $group ][] = array(
                    'display'  => $display,
                    'name'     => $name ?: $email,
                    'email'    => $email,
                    'director' => $director,
                    'course'   => $course,
                );
                $entry_groups[] = $group;
            }
        }

        $entry_groups = array_values( array_unique( $entry_groups ) );
        if ( empty( $entry_groups ) ) {
            $entry_groups = array( 'providers' );
        }

        if ( ! isset( $calendar_events_map[ $date ] ) ) {
            $calendar_events_map[ $date ] = array(
                'date'   => $date,
                'groups' => array(),
                'titles' => array(
                    'providers'    => array(),
                    'coordinators' => array(),
                ),
                'events' => array(),
            );
        }

        foreach ( $entry_groups as $g ) {
            $calendar_events_map[ $date ]['groups'][] = $g;
            $calendar_events_map[ $date ]['titles'][ $g ][] = $title;
        }

        $calendar_events_map[ $date ]['events'][] = array(
            'title'      => $title,
            'groups'     => $entry_groups,
            'recipients' => $recipients,
        );
    }

    $calendar_events = array();
    foreach ( $calendar_events_map as $date => $payload ) {
        $groups = array();
        if ( ! empty( $payload['groups'] ) ) {
            $groups = array_values( array_filter( array_map( 'sanitize_key', $payload['groups'] ), function ( $group ) {
                return in_array( $group, array( 'providers', 'coordinators' ), true );
            } ) );
            $groups = array_values( array_unique( $groups ) );
        }
        if ( empty( $groups ) ) {
            $groups = array( 'providers' );
        }

        $providers_titles    = array();
        $coordinators_titles = array();
        if ( ! empty( $payload['titles']['providers'] ) ) {
            $providers_titles = array_values( array_unique( array_map( 'sanitize_text_field', $payload['titles']['providers'] ) ) );
        }
        if ( ! empty( $payload['titles']['coordinators'] ) ) {
            $coordinators_titles = array_values( array_unique( array_map( 'sanitize_text_field', $payload['titles']['coordinators'] ) ) );
        }

        $events_payload = array();
        if ( ! empty( $payload['events'] ) && is_array( $payload['events'] ) ) {
            foreach ( $payload['events'] as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $entry_title = isset( $entry['title'] ) ? sanitize_text_field( $entry['title'] ) : '';
                $entry_groups = array_values( array_filter( array_unique( array_map( 'sanitize_key', $entry['groups'] ?? array() ) ), function ( $group ) {
                    return in_array( $group, array( 'providers', 'coordinators' ), true );
                } ) );
                if ( empty( $entry_groups ) ) {
                    $entry_groups = $groups;
                }

                $recipients_payload = array(
                    'providers'    => array(),
                    'coordinators' => array(),
                );

                if ( isset( $entry['recipients']['providers'] ) && is_array( $entry['recipients']['providers'] ) ) {
                    foreach ( $entry['recipients']['providers'] as $r ) {
                        if ( ! is_array( $r ) ) {
                            continue;
                        }
                        $recipients_payload['providers'][] = array(
                            'display'  => isset( $r['display'] ) ? sanitize_text_field( $r['display'] ) : '',
                            'name'     => isset( $r['name'] ) ? sanitize_text_field( $r['name'] ) : '',
                            'email'    => isset( $r['email'] ) ? sanitize_email( $r['email'] ) : '',
                            'director' => isset( $r['director'] ) ? sanitize_text_field( $r['director'] ) : '',
                            'course'   => isset( $r['course'] ) ? sanitize_text_field( $r['course'] ) : '',
                        );
                    }
                }
                if ( isset( $entry['recipients']['coordinators'] ) && is_array( $entry['recipients']['coordinators'] ) ) {
                    foreach ( $entry['recipients']['coordinators'] as $r ) {
                        if ( ! is_array( $r ) ) {
                            continue;
                        }
                        $recipients_payload['coordinators'][] = array(
                            'display'  => isset( $r['display'] ) ? sanitize_text_field( $r['display'] ) : '',
                            'name'     => isset( $r['name'] ) ? sanitize_text_field( $r['name'] ) : '',
                            'email'    => isset( $r['email'] ) ? sanitize_email( $r['email'] ) : '',
                            'director' => isset( $r['director'] ) ? sanitize_text_field( $r['director'] ) : '',
                            'course'   => isset( $r['course'] ) ? sanitize_text_field( $r['course'] ) : '',
                        );
                    }
                }

                $events_payload[] = array(
                    'title'      => $entry_title,
                    'groups'     => $entry_groups,
                    'recipients' => $recipients_payload,
                );
            }
        }

        $calendar_events[] = array(
            'date'   => $payload['date'],
            'groups' => $groups,
            'titles' => array(
                'providers'    => $providers_titles,
                'coordinators' => $coordinators_titles,
            ),
            'events' => $events_payload,
        );
    }

    if ( ! empty( $calendar_events ) ) {
        usort( $calendar_events, function ( $a, $b ) {
            return strcmp( $a['date'], $b['date'] );
        } );
    }

    $calendar_attr = esc_attr( wp_json_encode( $calendar_events, JSON_UNESCAPED_UNICODE ) );

    $faepa_returns = array();
    if ( function_exists( 'apf_get_coordinator_requests' ) ) {
        $requests = apf_get_coordinator_requests();
        if ( is_array( $requests ) ) {
            foreach ( $requests as $entry ) {
                if ( empty( $entry['faepa_forwarded'] ) ) {
                    continue;
                }
                $batch_id = '';
                if ( ! empty( $entry['batch_id'] ) ) {
                    $batch_id = sanitize_text_field( (string) $entry['batch_id'] );
                }
                if ( '' === $batch_id && ! empty( $entry['id'] ) ) {
                    $batch_id = sanitize_text_field( (string) $entry['id'] );
                }
                if ( '' === $batch_id ) {
                    continue;
                }

                $status = isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'pending';
                if ( 'approved' !== $status ) {
                    continue;
                }

                if ( ! isset( $faepa_returns[ $batch_id ] ) ) {
                    $title   = isset( $entry['note_title'] ) ? sanitize_text_field( $entry['note_title'] ) : '';
                    $message = isset( $entry['note_body'] ) ? sanitize_textarea_field( $entry['note_body'] ) : '';
                    $faepa_returns[ $batch_id ] = array(
                        'id'             => $batch_id,
                        'title'          => $title ?: 'Retorno do coordenador',
                        'message'        => $message,
                        'forwarded_at'   => isset( $entry['faepa_forwarded_at'] ) ? (int) $entry['faepa_forwarded_at'] : 0,
                        'forwarded_label'=> '',
                        'counts'         => array(
                            'total'    => 0,
                            'approved' => 0,
                            'rejected' => 0,
                            'pending'  => 0,
                            'paid'     => 0,
                            'notified' => 0,
                        ),
                        'coordinator'    => array(
                            'name'  => isset( $entry['coordinator_name'] ) ? sanitize_text_field( (string) $entry['coordinator_name'] ) : '',
                            'email' => isset( $entry['coordinator_email'] ) ? sanitize_email( $entry['coordinator_email'] ) : '',
                            'course'=> isset( $entry['course'] ) ? sanitize_text_field( (string) $entry['course'] ) : '',
                        ),
                        'faepa_note'     => isset( $entry['faepa_forwarded_note'] ) ? sanitize_textarea_field( $entry['faepa_forwarded_note'] ) : '',
                        'items'          => array(),
                    );
                }

                $faepa_returns[ $batch_id ]['counts']['total']++;
                $faepa_returns[ $batch_id ]['counts']['approved']++;
                if ( ! isset( $faepa_returns[ $batch_id ]['counts']['paid'] ) ) {
                    $faepa_returns[ $batch_id ]['counts']['paid'] = 0;
                }
                $decision_at   = isset( $entry['decision_at'] ) ? (int) $entry['decision_at'] : 0;
                $forwarded_at  = isset( $entry['faepa_forwarded_at'] ) ? (int) $entry['faepa_forwarded_at'] : 0;
                if ( $forwarded_at > $faepa_returns[ $batch_id ]['forwarded_at'] ) {
                    $faepa_returns[ $batch_id ]['forwarded_at'] = $forwarded_at;
                }

                $details = function_exists( 'apf_coord_build_request_details' )
                    ? apf_coord_build_request_details( $entry )
                    : array();

                $status_label = ( 'approved' === $status )
                    ? 'Aprovado'
                    : ( 'rejected' === $status ? 'Recusado' : 'Pendente' );

                $faepa_returns[ $batch_id ]['items'][] = array(
                    'id'             => isset( $entry['id'] ) ? sanitize_text_field( (string) $entry['id'] ) : '',
                    'name'           => isset( $entry['provider_name'] ) ? sanitize_text_field( (string) $entry['provider_name'] ) : '',
                    'value'          => isset( $entry['provider_value'] ) ? sanitize_text_field( (string) $entry['provider_value'] ) : '',
                    'provider_email' => isset( $entry['provider_email'] ) ? sanitize_email( (string) $entry['provider_email'] ) : '',
                    'status'         => $status,
                    'status_label'   => $status_label,
                    'decision_label' => $decision_at ? date_i18n( 'd/m/Y H:i', $decision_at ) : '',
                    'note'           => isset( $entry['decision_note'] ) ? sanitize_textarea_field( $entry['decision_note'] ) : '',
                    'details'        => $details,
                    'faepa_paid'     => ! empty( $entry['faepa_paid'] ),
                    'faepa_paid_at'  => isset( $entry['faepa_paid_at'] ) ? (int) $entry['faepa_paid_at'] : 0,
                    'faepa_paid_label'=> ( ! empty( $entry['faepa_paid_at'] ) ) ? date_i18n( 'd/m/Y H:i', (int) $entry['faepa_paid_at'] ) : '',
                    'faepa_payment_note' => isset( $entry['faepa_payment_note'] ) ? sanitize_textarea_field( $entry['faepa_payment_note'] ) : '',
                    'faepa_payment_attachment' => isset( $entry['faepa_payment_attachment'] ) ? esc_url_raw( $entry['faepa_payment_attachment'] ) : '',
                    'faepa_payment_notified' => ! empty( $entry['faepa_payment_notified'] ),
                    'faepa_payment_notified_label' => ( ! empty( $entry['faepa_payment_notified_at'] ) ) ? date_i18n( 'd/m/Y H:i', (int) $entry['faepa_payment_notified_at'] ) : '',
                );
                if ( ! empty( $entry['faepa_paid'] ) ) {
                    $faepa_returns[ $batch_id ]['counts']['paid']++;
                }
                if ( ! empty( $entry['faepa_payment_notified'] ) ) {
                    $faepa_returns[ $batch_id ]['counts']['notified']++;
                }
            }
        }
    }

    if ( ! empty( $faepa_returns ) ) {
        foreach ( $faepa_returns as $batch_id => $data ) {
            if ( empty( $data['counts']['total'] ) ) {
                unset( $faepa_returns[ $batch_id ] );
                continue;
            }
            $faepa_returns[ $batch_id ]['forwarded_label'] = ! empty( $data['forwarded_at'] )
                ? date_i18n( 'd/m/Y H:i', (int) $data['forwarded_at'] )
                : '';
        }
        $faepa_returns = array_values( $faepa_returns );
        usort( $faepa_returns, function ( $a, $b ) {
            $a_ts = isset( $a['forwarded_at'] ) ? (int) $a['forwarded_at'] : 0;
            $b_ts = isset( $b['forwarded_at'] ) ? (int) $b['forwarded_at'] : 0;
            if ( $a_ts === $b_ts ) {
                return 0;
            }
            return ( $a_ts > $b_ts ) ? -1 : 1;
        } );
    }

    ob_start();
    ?>
    <div class="apf-faepa" id="apfFaepaPortal">
      <div class="apf-faepa__hero">
        <div>
          <p class="apf-faepa__eyebrow">Portal FAEPA</p>
          <h2>Agenda completa do financeiro</h2>
          <p class="apf-faepa__lede">Visualize todos os avisos para colaboradores e coordenadores, em um só lugar.</p>
        </div>
      </div>

      <?php if ( $faepa_notice ) : ?>
        <div class="apf-faepa__notice apf-faepa__notice--<?php echo esc_attr( $faepa_notice_type ); ?>">
          <?php echo esc_html( $faepa_notice ); ?>
        </div>
      <?php endif; ?>

      <div class="apf-faepa-calendar" id="apfFaepaCalendar" data-events="<?php echo $calendar_attr; ?>">
        <div class="apf-faepa-calendar__tabs" role="tablist">
          <button type="button" class="apf-faepa-calendar__tab is-active" data-faepa-group="providers" aria-pressed="true">Colaboradores</button>
          <button type="button" class="apf-faepa-calendar__tab" data-faepa-group="coordinators" aria-pressed="false">Coordenadores</button>
        </div>
        <div class="apf-faepa-calendar__body"></div>
        <div class="apf-faepa-calendar__legend" aria-label="Legenda do calendário">
          <span><span class="apf-faepa-calendar__dot apf-faepa-calendar__dot--providers" aria-hidden="true"></span> Colaboradores</span>
          <span><span class="apf-faepa-calendar__dot apf-faepa-calendar__dot--coordinators" aria-hidden="true"></span> Coordenadores</span>
        </div>
      </div>
      <?php if ( empty( $calendar_events ) ) : ?>
        <p class="apf-faepa__empty">Nenhum aviso programado até o momento.</p>
      <?php else : ?>
        <p class="apf-faepa__hint">Clique nos dias destacados para abrir os avisos.</p>
      <?php endif; ?>

      <section class="apf-faepa-return">
        <div class="apf-faepa-return__head">
          <div>
            <p class="apf-faepa__eyebrow">Retornos enviados</p>
            <h3>Dados validados pelo financeiro</h3>
            <p>Consulte os lotes que o financeiro encaminhou para aprovação/pagamento.</p>
          </div>
          <?php if ( ! empty( $faepa_returns ) ) : ?>
            <span class="apf-faepa-return__badge"><?php echo esc_html( count( $faepa_returns ) . ' lote(s)' ); ?></span>
          <?php endif; ?>
        </div>

          <?php if ( empty( $faepa_returns ) ) : ?>
            <p class="apf-faepa-return__empty">Nenhum retorno recebido do financeiro até o momento.</p>
          <?php else : ?>
            <div class="apf-table-scroller apf-faepa-return__scroller" tabindex="0" aria-label="Retornos enviados">
              <div class="apf-faepa-return__table" role="table" aria-label="Retornos do financeiro">
                <div class="apf-faepa-return__pager apf-pager-row" role="row">
                  <div class="apf-pager-row__right">
                    <div class="apf-pager" id="apfFaepaReturnPager" data-faepa-return-pager aria-label="Paginação dos lotes">
                      <button type="button" class="apf-pager__btn" id="apfFaepaReturnPrev" data-faepa-return-prev aria-label="Página anterior">&larr;</button>
                      <span class="apf-pager__label" id="apfFaepaReturnLabel" data-faepa-return-label>1/1</span>
                      <button type="button" class="apf-pager__btn" id="apfFaepaReturnNext" data-faepa-return-next aria-label="Próxima página">&rarr;</button>
                    </div>
                  </div>
                </div>
            <?php foreach ( $faepa_returns as $return ) :
                $counts = $return['counts'];
                if ( ! isset( $counts['paid'] ) ) {
                    $counts['paid'] = 0;
                }
                $course = $return['coordinator']['course'] ?? '';
                $coord  = $return['coordinator']['name'] ?? '';
                $forwarded_label = $return['forwarded_label'];
                $title_full  = isset( $return['title'] ) ? (string) $return['title'] : '';
                $title_short = preg_replace( '/^\s*Lote\s*-\s*/i', '', $title_full );
                $title_short = trim( $title_short );
                if ( '' === $title_short ) {
                    $title_short = $title_full;
                }
            ?>
              <details class="apf-faepa-return__row" role="row">
                <summary>
                  <span class="apf-faepa-return__cell apf-faepa-return__cell--title" data-label="Lote">
                    <strong>
                      <span class="apf-faepa-return__title-full"><?php echo esc_html( $title_full ); ?></span>
                      <span class="apf-faepa-return__title-short"><?php echo esc_html( $title_short ); ?></span>
                    </strong>
                  </span>
                  <span class="apf-faepa-return__cell" data-label="Enviado"><?php echo esc_html( $forwarded_label ?: '—' ); ?></span>
                  <span class="apf-faepa-return__cell" data-label="Coordenador"><?php echo esc_html( $coord ?: '—' ); ?></span>
                  <span class="apf-faepa-return__cell apf-faepa-return__cell--course" data-label="Curso">
                    <span class="apf-faepa-return__value apf-faepa-return__value--course"><?php echo esc_html( $course ?: '—' ); ?></span>
                  </span>
                  <span class="apf-faepa-return__cell" data-label="Colaboradores">
                    <span class="apf-faepa-chip apf-faepa-chip--info"><?php echo esc_html( $counts['total'] . ' colaborador(es)' ); ?></span>
                  </span>
                  <span class="apf-faepa-return__cell" data-label="Pagos">
                    <span class="apf-faepa-chip apf-faepa-chip--success" data-faepa-notify-count><?php echo esc_html( ( $counts['paid'] ?? 0 ) . ' pagos' ); ?></span>
                  </span>
                </summary>

                <div class="apf-faepa-return__row-details" data-faepa-batch="<?php echo esc_attr( $return['id'] ); ?>" data-faepa-total="<?php echo esc_attr( isset( $counts['total'] ) ? (int) $counts['total'] : 0 ); ?>" data-faepa-approved="<?php echo esc_attr( isset( $counts['paid'] ) ? (int) $counts['paid'] : 0 ); ?>">
                  <?php if ( $forwarded_label ) : ?>
                    <p class="apf-faepa-return__meta"><strong>Enviado pelo financeiro:</strong> <?php echo esc_html( $forwarded_label ); ?></p>
                  <?php endif; ?>
                  <?php if ( $course || $coord ) : ?>
                    <p class="apf-faepa-return__meta">
                      <?php if ( $coord ) : ?>
                        Coordenador: <?php echo esc_html( $coord ); ?>
                      <?php endif; ?>
                      <?php if ( $course ) : ?>
                        <?php if ( $coord ) : ?> • <?php endif; ?>
                        Curso: <?php echo esc_html( $course ); ?>
                      <?php endif; ?>
                    </p>
                  <?php endif; ?>
                  <?php if ( ! empty( $return['faepa_note'] ) ) : ?>
                    <p class="apf-faepa-return__note"><?php echo esc_html( $return['faepa_note'] ); ?></p>
                  <?php endif; ?>
                  <?php
                    $all_paid = ( isset( $counts['approved'], $counts['paid'] ) && $counts['approved'] > 0 && $counts['paid'] >= $counts['approved'] );
                  ?>
                  <h5 class="apf-faepa-return__section-title">Colaboradores (<?php echo esc_html( $counts['total'] ); ?>)</h5>
                  <div class="apf-faepa-return__list">
                    <?php foreach ( $return['items'] as $item ) :
                        if ( isset( $item['status'] ) && 'approved' !== $item['status'] ) {
                            continue;
                        }
                        $detail_payment = $item['details']['payment'] ?? array();
                        $detail_service = $item['details']['service'] ?? array();
                        $detail_payout  = $item['details']['payout'] ?? array();
                        $company_label  = '';
                        if ( isset( $detail_payment['Empresa (PJ)'] ) ) {
                            $company_label = sanitize_text_field( (string) $detail_payment['Empresa (PJ)'] );
                        } elseif ( isset( $detail_payment['Nome da Empresa'] ) ) {
                            $company_label = sanitize_text_field( (string) $detail_payment['Nome da Empresa'] );
                        }
                        if ( '—' === $company_label ) {
                            $company_label = '';
                        }
                        $value_label = '';
                        if ( ! empty( $item['value'] ) ) {
                            $value_label = trim( (string) $item['value'] );
                            if ( stripos( $value_label, 'r$' ) !== 0 ) {
                                $value_label = 'R$ ' . $value_label;
                            }
                        }
                    ?>
                      <article class="apf-faepa-entry" data-faepa-approved="<?php echo ! empty( $item['faepa_paid'] ) ? '1' : '0'; ?>">
                        <details>
                          <summary class="apf-faepa-entry__head">
                            <div>
                              <div class="apf-faepa-entry__title-line">
                                <strong class="apf-faepa-entry__name"><?php echo esc_html( $item['name'] ?: 'Colaborador' ); ?></strong>
                                <?php if ( $value_label ) : ?>
                                  <span class="apf-faepa-entry__separator" aria-hidden="true">—</span>
                                  <span class="apf-faepa-entry__value"><?php echo esc_html( $value_label ); ?></span>
                                <?php endif; ?>
                              </div>
                            <?php if ( $company_label && $company_label !== ( $item['name'] ?? '' ) ) : ?>
                                <div class="apf-faepa-entry__company"><?php echo esc_html( $company_label ); ?></div>
                              <?php endif; ?>
                            </div>
                            <?php if ( ! empty( $item['faepa_paid'] ) ) : ?>
                              <span class="apf-faepa-pill apf-faepa-pill--<?php echo esc_attr( $item['status'] ); ?>">
                                <?php echo esc_html( $item['status_label'] ); ?>
                              </span>
                            <?php endif; ?>
                          </summary>
                          <div class="apf-faepa-entry__body">
                            <ul class="apf-faepa-entry__meta">
                              <?php if ( $item['decision_label'] ) : ?>
                                <li><?php echo esc_html( $item['decision_label'] ); ?></li>
                              <?php endif; ?>
                              <?php if ( $item['note'] ) : ?>
                                <li>Observação: <?php echo esc_html( $item['note'] ); ?></li>
                              <?php endif; ?>
                            <?php
                              $paid_label = '';
                              if ( ! empty( $item['faepa_paid_label'] ) ) {
                                  $paid_label = $item['faepa_paid_label'];
                              }
                            ?>
                              <li class="apf-faepa-payment-inline<?php echo $paid_label ? '' : ' is-hidden'; ?>"><strong>Pagamento confirmado:</strong> <span data-faepa-paid-label><?php echo esc_html( $paid_label ); ?></span></li>
                            </ul>

                            <?php if ( ! empty( $item['faepa_payment_notified'] ) ) : ?>
                              <p class="apf-faepa-entry__note">Notificação enviada<?php echo $item['faepa_payment_notified_label'] ? ' em ' . esc_html( $item['faepa_payment_notified_label'] ) : ''; ?>.</p>
                            <?php else : ?>
                            <div class="apf-faepa-approve__actions">
                              <?php if ( empty( $item['faepa_paid'] ) && ! empty( $item['id'] ) ) : ?>
                                <form method="post">
                                  <?php wp_nonce_field( 'apf_faepa_approve', 'apf_faepa_approve_nonce' ); ?>
                                  <input type="hidden" name="apf_faepa_approve_action" value="1">
                                  <input type="hidden" name="apf_faepa_request_id" value="<?php echo esc_attr( $item['id'] ); ?>">
                                  <button type="submit" class="apf-faepa-approve__btn" data-faepa-approve data-faepa-batch="<?php echo esc_attr( $return['id'] ); ?>">Aprovar pagamento</button>
                                  <p class="apf-faepa-approve__hint">Marque como aprovado para liberar a notificação.</p>
                                </form>
                              <?php endif; ?>
                              <p class="apf-faepa-approve__status<?php echo ! empty( $item['faepa_paid'] ) ? ' is-visible' : ''; ?>">Aprovado</p>
                            </div>

                            <?php if ( ! empty( $item['faepa_payment_note'] ) || ! empty( $item['faepa_payment_attachment'] ) ) : ?>
                              <div class="apf-faepa-entry__block">
                                <strong>Comprovante do pagamento</strong>
                                <?php if ( ! empty( $item['faepa_payment_note'] ) ) : ?>
                                  <p class="apf-faepa-pay__note"><?php echo esc_html( $item['faepa_payment_note'] ); ?></p>
                                <?php endif; ?>
                                <?php if ( ! empty( $item['faepa_payment_attachment'] ) ) : ?>
                                  <p class="apf-faepa-pay__note">
                                    <a href="<?php echo esc_url( $item['faepa_payment_attachment'] ); ?>" target="_blank" rel="noopener">Ver anexo do comprovante</a>
                                  </p>
                                <?php endif; ?>
                              </div>
                            <?php endif; ?>

                            <?php if ( ! empty( $detail_payment ) ) : ?>
                              <div class="apf-faepa-entry__block apf-faepa-entry__block--payment">
                                <strong>Informações de pagamento</strong>
                                <dl>
                                  <?php foreach ( $detail_payment as $label => $value ) : ?>
                                    <dt><?php echo esc_html( $label ); ?></dt>
                                    <dd><?php echo esc_html( $value === '' ? '—' : $value ); ?></dd>
                                  <?php endforeach; ?>
                                </dl>
                              </div>
                            <?php endif; ?>

                            <?php if ( ! empty( $detail_service ) ) : ?>
                              <div class="apf-faepa-entry__block apf-faepa-entry__block--emphasis">
                                <strong>Prestação de serviço</strong>
                                <dl>
                                  <?php foreach ( $detail_service as $label => $value ) : ?>
                                    <dt><?php echo esc_html( $label ); ?></dt>
                                    <dd><?php echo esc_html( $value === '' ? '—' : $value ); ?></dd>
                                  <?php endforeach; ?>
                                </dl>
                              </div>
                            <?php endif; ?>

                            <?php if ( ! empty( $detail_payout ) ) : ?>
                              <div class="apf-faepa-entry__block apf-faepa-entry__block--emphasis">
                                <strong>Dados para pagamento</strong>
                                <dl>
                                  <?php foreach ( $detail_payout as $label => $value ) : ?>
                                    <dt><?php echo esc_html( $label ); ?></dt>
                                    <dd><?php echo esc_html( $value === '' ? '—' : $value ); ?></dd>
                                  <?php endforeach; ?>
                                </dl>
                              </div>
                            <?php endif; ?>
                            <?php endif; // fim notificado ?>
                          </div>
                        </details>
                      </article>
                    <?php endforeach; ?>
                  </div>

                  <div class="apf-faepa-return__notify">
                    <?php
                      $notified_count = isset( $return['counts']['notified'] ) ? (int) $return['counts']['notified'] : 0;
                      $approved_count = isset( $return['counts']['approved'] ) ? (int) $return['counts']['approved'] : 0;
                      $all_notified   = ( $approved_count > 0 && $notified_count >= $approved_count );
                    ?>
                    <?php if ( $all_notified ) : ?>
                      <p class="apf-faepa-return__note">Notificações enviadas para colaboradores, financeiro e coordenadores.</p>
                    <?php else : ?>
                      <form method="post" class="apf-faepa-notify" data-faepa-notify-form>
                        <?php wp_nonce_field( 'apf_faepa_notify', 'apf_faepa_notify_nonce' ); ?>
                        <input type="hidden" name="apf_faepa_notify_action" value="1">
                        <input type="hidden" name="apf_faepa_batch_id" value="<?php echo esc_attr( $return['id'] ); ?>">
                        <button type="submit" class="apf-faepa-notify__btn<?php echo $all_paid ? '' : ' is-disabled'; ?>" data-faepa-notify-btn <?php echo $all_paid ? '' : 'disabled'; ?>>Enviar notificações de pagamento</button>
                      </form>
                      <p class="apf-faepa-return__note apf-faepa-return__note--muted" data-faepa-notify-hint><?php echo $all_paid ? 'Todos aprovados. Pode enviar a notificação.' : 'Aprove todos os pagamentos para liberar a notificação.'; ?></p>
                    <?php endif; ?>
                  </div>
                </div>
              </details>
            <?php endforeach; ?>
              </div>
            </div>
        <?php endif; ?>
      </section>
  </div>

  <div class="apf-faepa-modal" id="apfFaepaModal" aria-hidden="true">
    <div class="apf-faepa-modal__overlay" data-modal-close></div>
    <div class="apf-faepa-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfFaepaModalTitle">
      <div class="apf-faepa-modal__head">
        <div>
            <p class="apf-faepa__eyebrow">Avisos do dia</p>
            <h4 id="apfFaepaModalTitle">Avisos</h4>
          </div>
          <button type="button" class="apf-faepa-modal__close" data-modal-close aria-label="Fechar">&times;</button>
        </div>
        <div class="apf-faepa-modal__content">
          <p class="apf-faepa-modal__empty">Nenhum aviso programado para esta data.</p>
          <div class="apf-faepa-modal__list"></div>
    </div>
  </div>
  </div>

  <script>
    (function(){
      var approveAlreadyInit = !!window.apfFaepaApproveInit;
      window.apfFaepaApproveInit = true;

      function findParent(el, selector){
        while(el){
          if(el.matches && el.matches(selector)){ return el; }
          el = el.parentElement;
        }
        return null;
      }

      function updateNotifyForBatch(batchEl){
        if(!batchEl){ return; }
        var entries = Array.prototype.slice.call(batchEl.querySelectorAll('.apf-faepa-entry[data-faepa-approved]'));
        var total = entries.length;
        var approved = entries.filter(function(entry){
          return entry.getAttribute('data-faepa-approved') === '1';
        }).length;
        batchEl.setAttribute('data-faepa-total', total);
        batchEl.setAttribute('data-faepa-approved', approved);
        var canNotify = total > 0 && approved >= total;
        var notifyBtn = batchEl.querySelector('[data-faepa-notify-btn]');
        var notifyHint = batchEl.querySelector('[data-faepa-notify-hint]');
        var notifyCount = batchEl.querySelector('[data-faepa-notify-count]');
        if(!notifyCount){
          var row = findParent(batchEl, '.apf-faepa-return__row');
          if(row){
            notifyCount = row.querySelector('[data-faepa-notify-count]');
          }
        }
        if(notifyBtn){
          notifyBtn.disabled = !canNotify;
          if(canNotify){
            notifyBtn.classList.remove('is-disabled');
          } else {
            notifyBtn.classList.add('is-disabled');
          }
        }
        if(notifyHint){
          notifyHint.textContent = canNotify
            ? 'Todos aprovados. Pode enviar a notificação.'
            : 'Aprove todos os pagamentos para liberar a notificação.';
        }
        if(notifyCount){
          notifyCount.textContent = approved + ' pagos';
        }
      }

      function initReturnPager(){
        var tables = document.querySelectorAll('.apf-faepa-return__table');
        if(!tables.length){ return; }
        tables.forEach(function(table){
          if(table.dataset.apfPagerInit === '1'){ return; }
          table.dataset.apfPagerInit = '1';

          var rows = Array.prototype.slice.call(table.querySelectorAll('.apf-faepa-return__row'));
          if(!rows.length){ return; }

          var scroller = table.closest('.apf-faepa-return__scroller') || table;
          var pagerRow = table.querySelector('.apf-faepa-return__pager');
          var prevBtn = table.querySelector('[data-faepa-return-prev]');
          var nextBtn = table.querySelector('[data-faepa-return-next]');
          var pagerLabel = table.querySelector('[data-faepa-return-label]');
          var pageSize = 10;
          var page = 0;

          function isTypingTarget(target){
            if(!target){ return false; }
            if(target.isContentEditable){ return true; }
            var tag = target.tagName;
            return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
          }

          function isInteractiveTarget(target){
            if(!target){ return false; }
            if(target.isContentEditable){ return true; }
            if(target.closest){
              return !!target.closest('a, button, input, select, textarea, summary, [role=\"button\"]');
            }
            return false;
          }

          function setBtnState(btn, disabled){
            if(!btn){ return; }
            btn.disabled = disabled;
            btn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
          }

          function getTotalPages(){
            return Math.max(1, Math.ceil(rows.length / pageSize));
          }

          function clampPage(nextPage){
            var totalPages = getTotalPages();
            if(nextPage < 0){ nextPage = 0; }
            if(nextPage >= totalPages){ nextPage = totalPages - 1; }
            return nextPage;
          }

          function renderPage(){
            var total = rows.length;
            var totalPages = getTotalPages();
            page = clampPage(page);
            var start = page * pageSize;
            var end = start + pageSize;
            rows.forEach(function(row, index){
              var hide = index < start || index >= end;
              row.classList.toggle('apf-page-hide', hide);
            });
            if(pagerLabel){
              pagerLabel.textContent = total ? ((page + 1) + '/' + totalPages) : '0/0';
            }
            setBtnState(prevBtn, total === 0 || page <= 0);
            setBtnState(nextBtn, total === 0 || page >= totalPages - 1);
            var showControls = totalPages > 1;
            if(prevBtn){ prevBtn.style.visibility = showControls ? 'visible' : 'hidden'; }
            if(nextBtn){ nextBtn.style.visibility = showControls ? 'visible' : 'hidden'; }
            if(pagerRow){ pagerRow.hidden = !showControls; }
          }

          function setPage(nextPage){
            page = clampPage(nextPage);
            renderPage();
            if(scroller){
              scroller.scrollTop = 0;
            }
          }

          if(prevBtn){
            prevBtn.addEventListener('click', function(){
              setPage(page - 1);
            });
          }
          if(nextBtn){
            nextBtn.addEventListener('click', function(){
              setPage(page + 1);
            });
          }
          if(scroller){
            if(!scroller.hasAttribute('tabindex')){
              scroller.setAttribute('tabindex','0');
            }
            if(scroller.dataset.apfPagerBound !== '1'){
              scroller.dataset.apfPagerBound = '1';
              scroller.addEventListener('mousedown', function(event){
                if(isInteractiveTarget(event.target)){ return; }
                try {
                  scroller.focus({ preventScroll: true });
                } catch(_e){
                  scroller.focus();
                }
              });
              scroller.addEventListener('keydown', function(event){
                if(event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey){ return; }
                if(isTypingTarget(event.target)){ return; }
                if(event.key === 'ArrowLeft'){
                  event.preventDefault();
                  setPage(page - 1);
                  return;
                }
                if(event.key === 'ArrowRight'){
                  event.preventDefault();
                  setPage(page + 1);
                }
              });
            }
          }

          renderPage();
        });
      }

      function captureEntryState(article){
        if(!article){ return null; }
        var btn = article.querySelector('[data-faepa-approve]');
        var status = article.querySelector('.apf-faepa-approve__status');
        var paidLabel = article.querySelector('[data-faepa-paid-label]');
        var paidLine = paidLabel ? paidLabel.closest('.apf-faepa-payment-inline') : null;
        return {
          approvedAttr: article.getAttribute('data-faepa-approved') || '0',
          entryApproved: article.classList.contains('apf-faepa-entry--approved'),
          statusVisible: status ? status.classList.contains('is-visible') : false,
          btnDisabled: btn ? btn.disabled : false,
          btnHidden: btn ? btn.classList.contains('is-hidden') : false,
          paidText: paidLabel ? paidLabel.textContent : '',
          paidVisible: paidLine ? !paidLine.classList.contains('is-hidden') : false
        };
      }

      function applyApprovedState(article){
        if(!article){ return; }
        var btn = article.querySelector('[data-faepa-approve]');
        if(btn){
          btn.disabled = true;
          btn.classList.add('is-hidden');
        }
        article.setAttribute('data-faepa-approved','1');
        article.classList.add('apf-faepa-entry--approved');
        var status = article.querySelector('.apf-faepa-approve__status');
        if(status){ status.classList.add('is-visible'); }
        var paidLabel = article.querySelector('[data-faepa-paid-label]');
        if(paidLabel){
          var now = new Date();
          paidLabel.textContent = now.toLocaleString('pt-BR');
          var li = paidLabel.closest('.apf-faepa-payment-inline');
          if(li){ li.classList.remove('is-hidden'); }
        }
      }

      function restoreEntryState(article, previous){
        if(!article || !previous){ return; }
        var btn = article.querySelector('[data-faepa-approve]');
        if(btn){
          btn.disabled = !!previous.btnDisabled;
          btn.classList.toggle('is-hidden', !!previous.btnHidden);
        }
        article.setAttribute('data-faepa-approved', previous.approvedAttr || '0');
        if(previous.entryApproved){
          article.classList.add('apf-faepa-entry--approved');
        } else {
          article.classList.remove('apf-faepa-entry--approved');
        }
        var status = article.querySelector('.apf-faepa-approve__status');
        if(status){
          status.classList.toggle('is-visible', !!previous.statusVisible);
        }
        var paidLabel = article.querySelector('[data-faepa-paid-label]');
        var paidLine = paidLabel ? paidLabel.closest('.apf-faepa-payment-inline') : null;
        if(paidLabel && typeof previous.paidText === 'string'){
          paidLabel.textContent = previous.paidText;
        }
        if(paidLine){
          paidLine.classList.toggle('is-hidden', !previous.paidVisible);
        }
      }

      function initFaepaApprove(){
        document.querySelectorAll('.apf-faepa-approve__actions form').forEach(function(form){
          form.addEventListener('submit', function(ev){
            ev.preventDefault();
            var btn = form.querySelector('[data-faepa-approve]');
            var article = btn ? btn.closest('.apf-faepa-entry') : null;
            var batchEl = form.closest('[data-faepa-batch]');
            var previous = captureEntryState(article);

            if(article){ applyApprovedState(article); }
            if(batchEl){ updateNotifyForBatch(batchEl); }

            var fd = new FormData(form);
            fd.append('apf_faepa_approve_ajax', '1');
            var action = form.getAttribute('action') || window.location.href;
            fetch(action, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              body: fd
            }).then(function(res){
              return res && res.ok ? res.json() : Promise.reject();
            })
            .then(function(payload){
              if(!(payload && payload.success)){
                if(article){ restoreEntryState(article, previous); }
                if(batchEl){ updateNotifyForBatch(batchEl); }
                var msg = (payload && payload.data && payload.data.message) ? payload.data.message : 'Não foi possível aprovar este pagamento.';
                alert(msg);
              }
            }).catch(function(){
              if(article){ restoreEntryState(article, previous); }
              if(batchEl){ updateNotifyForBatch(batchEl); }
              alert('Erro ao aprovar. Tente novamente.');
            });
          });
        });
        document.querySelectorAll('[data-faepa-batch]').forEach(function(batchEl){
          updateNotifyForBatch(batchEl);
        });
      }

      function initPortalFaepa(){
        if(!approveAlreadyInit){
          initFaepaApprove();
        }
        initReturnPager();
      }

      if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', initPortalFaepa);
      } else {
        initPortalFaepa();
      }
    })();
  </script>

  <style>
    .apf-faepa{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;--apf-ink:#0f172a;--apf-muted:#5f6b7a;--apf-border:#d6e1ed;--apf-soft:#f6f9fc;--apf-primary:#125791;--apf-primary-strong:#0f456e;--apf-primary-gradient:linear-gradient(120deg,#0f172a,var(--apf-primary));--apf-focus:0 0 0 3px rgba(18,87,145,.18),0 0 0 6px rgba(18,87,145,.12);max-width:1180px;margin:24px auto;padding:clamp(10px,2vw,24px);color:var(--apf-ink);background:transparent;box-sizing:border-box}
      .apf-faepa__hero{background:var(--apf-primary-gradient);color:#e2f3ff;border-radius:18px;padding:clamp(18px,2vw,26px);box-shadow:0 16px 36px rgba(15,23,42,.18);margin-bottom:18px;border:1px solid #000}
      .apf-faepa__eyebrow{margin:0 0 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:#e0f2fe}
      .apf-faepa__hero h2{margin:0;font-size:clamp(22px,3vw,28px);line-height:1.2}
      .apf-faepa__lede{margin:8px 0 0;font-size:14px;max-width:720px;color:#d9edff}
      .apf-faepa__notice{margin:12px 0;padding:12px 14px;border-radius:12px;font-size:13px;border:1px solid #cbd5e1;background:#f1f5f9;color:var(--apf-ink);box-shadow:0 10px 20px rgba(15,23,42,.06)}
      .apf-faepa__notice--error{border-color:#fecdd3;background:#fef2f2;color:#991b1b}
      .apf-faepa__notice--success{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
      .apf-faepa-calendar{background:#fff;border:1px solid #000;border-radius:16px;padding:16px;box-shadow:0 12px 28px rgba(15,23,42,.08)}
      .apf-faepa-calendar__tabs{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));border:1px solid #000;border-radius:12px;overflow:hidden;margin-bottom:12px}
      .apf-faepa-calendar__tab{border:none;background:#f8fafc;padding:10px 14px;font-size:13px;color:#475467;cursor:pointer;transition:background .15s ease,color .15s ease}
      .apf-faepa-calendar__tab + .apf-faepa-calendar__tab{border-left:1px solid #000}
      .apf-faepa-calendar__tab.is-active{background:var(--apf-primary-gradient);color:#fff;font-weight:700}
      .apf-faepa-calendar__body{display:flex;flex-direction:column;gap:12px}
      .apf-faepa-calendar__inner{display:flex;flex-direction:column;gap:12px}
      .apf-faepa-calendar__header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
      .apf-faepa-calendar__header h4{margin:0;font-size:18px;color:var(--apf-ink)}
      .apf-faepa-calendar__nav{display:flex;gap:8px}
      .apf-faepa-calendar__btn{width:34px;height:34px;border-radius:10px;border:1px solid #cfd4dc;background:#f8fafc;color:var(--apf-ink);font-weight:700;cursor:pointer;transition:all .15s ease}
      .apf-faepa-calendar__btn:hover,
      .apf-faepa-calendar__btn:focus{border-color:#0ea5e9;outline:none;box-shadow:var(--apf-focus)}
      .apf-faepa-calendar__weekdays,
      .apf-faepa-calendar__days{display:grid;grid-template-columns:repeat(7,minmax(36px,1fr));gap:8px}
      .apf-faepa-calendar__weekday{text-align:center;font-size:12px;font-weight:700;color:#475467;text-transform:uppercase;letter-spacing:.02em}
      .apf-faepa-calendar__day{position:relative;height:54px;border-radius:12px;border:1px solid #000;background:#f8fafc;color:var(--apf-ink);font-size:15px;font-weight:700;display:flex;align-items:center;justify-content:center;transition:border-color .15s ease,background .15s ease;outline:none}
      .apf-faepa-calendar__day--muted{opacity:.35}
      .apf-faepa-calendar__day--has-event{border-color:#0ea5e9;background:linear-gradient(160deg,rgba(14,165,233,.12),rgba(59,130,246,.08))}
      .apf-faepa-calendar__day--group-coordinators{border-color:#135288}
      .apf-faepa-calendar__day--group-providers{border-color:#0e182d}
      .apf-faepa-calendar__day--group-mixed{border-color:#0b6b94}
      .apf-faepa-calendar__day--has-event:hover{cursor:pointer;box-shadow:0 12px 28px rgba(14,165,233,.18)}
      .apf-faepa-calendar__markers{position:absolute;bottom:6px;left:50%;transform:translateX(-50%);display:flex;gap:6px}
      .apf-faepa-calendar__marker{width:10px;height:10px;border-radius:999px;border:1px solid #fff;box-shadow:0 0 0 1px rgba(15,23,42,.12)}
      .apf-faepa-calendar__marker--providers{background:#0e182d}
      .apf-faepa-calendar__marker--coordinators{background:#135288}
      .apf-faepa-calendar__legend{margin-top:6px;display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:#475467}
      .apf-faepa-calendar__dot{display:inline-block;width:12px;height:12px;border-radius:999px;margin-right:6px;vertical-align:middle}
      .apf-faepa-calendar__dot--providers{background:#0e182d}
      .apf-faepa-calendar__dot--coordinators{background:#135288}
      .apf-faepa__empty{margin:12px 2px 0;font-size:14px;color:#b42318;font-weight:600}
      .apf-faepa__hint{margin:12px 2px 0;font-size:13px;color:#475467}
      .apf-faepa-return{margin-top:20px;border:1px solid #000;border-radius:16px;padding:16px;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.08);display:flex;flex-direction:column;gap:14px}
      .apf-faepa-return__head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
      .apf-faepa-return__head h3{margin:4px 0 0;font-size:18px;color:var(--apf-ink)}
      .apf-faepa-return__head p{margin:6px 0 0;font-size:13px;color:var(--apf-muted);max-width:720px}
      .apf-faepa-return__badge{border:1px solid var(--apf-border);border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;color:#475467;background:#f8fafc}
      .apf-faepa-return__empty{margin:6px 0 0;font-size:13px;color:#b42318;font-weight:600}
      .apf-table-scroller{overflow:auto;overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #000;border-radius:14px;background:#fff;box-shadow:0 10px 20px rgba(15,23,42,.06)}
      .apf-faepa-return__scroller:focus,
      .apf-faepa-return__scroller:focus-visible,
      .apf-faepa-return__table:focus,
      .apf-faepa-return__table:focus-visible{outline:none;box-shadow:none}
      .apf-faepa-return__table{border:none;box-shadow:none;background:transparent}
      .apf-faepa-return__pager{padding:10px 12px;background:#f8fafc}
      .apf-pager-row{display:flex;align-items:center;justify-content:flex-end;gap:12px}
      .apf-pager-row__right{display:flex;align-items:center;justify-content:flex-end;gap:12px}
      .apf-pager{display:flex;align-items:center;gap:6px}
      .apf-pager__btn{width:34px;min-width:34px;height:34px;border-radius:999px;border:1px solid #000;background:#fff;color:#0f172a;font-size:16px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s ease, box-shadow .15s ease}
      .apf-pager__btn:hover{background:#f8fafc}
      .apf-pager__btn:focus-visible{outline:none;box-shadow:var(--apf-focus)}
      .apf-pager__btn:disabled{opacity:.4;cursor:not-allowed;box-shadow:none}
      .apf-pager__label{min-width:64px;text-align:center;font-size:12px;font-weight:700;color:#475467}
      .apf-faepa-return__row.apf-page-hide{display:none}
      .apf-faepa-return__table-head{display:grid;grid-template-columns:minmax(160px,1.6fr) repeat(4,minmax(130px,1fr)) 80px;gap:10px;align-items:center;padding:12px 14px;background:#f8fafc;font-size:12px;font-weight:700;color:#475467}
      .apf-faepa-return__cell{font-size:12px;color:#475467}
      .apf-faepa-return__cell--title strong{font-size:14px;color:#0f172a}
      .apf-faepa-return__title-short{display:none}
      .apf-faepa-return__row{border-top:1px solid #000}
      .apf-faepa-return__row summary{display:grid;grid-template-columns:minmax(160px,1.6fr) repeat(4,minmax(130px,1fr)) 80px;gap:10px;align-items:center;padding:12px 14px;cursor:pointer;list-style:none;outline:none}
      .apf-faepa-return__row summary:focus-visible{box-shadow:0 0 0 2px rgba(14,165,233,.4)}
      .apf-faepa-return__row summary::-webkit-details-marker{display:none}
      .apf-faepa-return__row[open] summary{background:#ecfeff}
      .apf-faepa-return__cell--caret{text-align:right}
      .apf-faepa-return__caret{transition:transform .15s ease;font-size:12px;color:#475467}
      .apf-faepa-return__row[open] .apf-faepa-return__caret{transform:rotate(180deg)}
      .apf-faepa-chip{padding:4px 8px;border-radius:10px;font-size:12px;font-weight:700}
      .apf-faepa-chip--info{background:rgba(14,165,233,.16);color:#075985;border:1px solid rgba(14,165,233,.3)}
      .apf-faepa-return__row-details{padding:12px 14px;background:#f9fbff;border-top:1px solid #e4e7ec;display:flex;flex-direction:column;gap:10px}
      .apf-faepa-return__meta{margin:0;font-size:12px;color:#475467}
      .apf-faepa-return__note{margin:0;font-size:13px;color:#0f172a;background:#e0f2fe;border:1px solid #bfdbfe;border-radius:10px;padding:10px 12px}
      .apf-faepa-return__section-title{margin:0;font-size:13px;font-weight:700;color:#0f172a}
      .apf-faepa-return__list{display:flex;flex-direction:column;gap:10px}
      .apf-faepa-entry{border:1px solid #000;border-radius:12px;padding:12px;background:#fff;display:flex;flex-direction:column;gap:8px}
      .apf-faepa-entry details summary{cursor:pointer;list-style:none;outline:none}
      .apf-faepa-entry details summary:focus-visible{box-shadow:0 0 0 2px rgba(14,165,233,.35)}
      .apf-faepa-entry details summary::-webkit-details-marker{display:none}
      .apf-faepa-entry__head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:13px}
      .apf-faepa-entry__title-line{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
      .apf-faepa-entry__head strong{font-size:13px}
      .apf-faepa-entry__value{color:#0f172a;font-weight:600}
      .apf-faepa-entry__company{font-size:12px;color:#475467;margin-top:2px}
      .apf-faepa-entry__separator{margin:0 6px;font-weight:600;color:#0ea5e9;font-size:12px}
      .apf-faepa-entry__value{font-weight:600;color:#0ea5e9;font-size:12px}
      .apf-faepa-pill{padding:4px 8px;border-radius:10px;font-size:12px;font-weight:700;background:#e5e7eb;color:#0f172a}
      .apf-faepa-pill--approved{background:rgba(16,185,129,.18);color:#047857}
      .apf-faepa-pill--rejected{background:rgba(248,113,113,.22);color:#991b1b}
      .apf-faepa-pill--pending{background:rgba(251,191,36,.22);color:#92400e}
      .apf-faepa-entry__meta{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:8px;font-size:12px;color:#475467}
      .apf-faepa-entry__note{margin:6px 0 0;font-size:13px;color:#0f172a;background:#ecfeff;border:1px dashed #7dd3fc;border-radius:10px;padding:10px 12px}
      .apf-faepa-entry__block{border-top:1px dashed #e4e7ec;padding-top:8px;margin-top:4px}
      .apf-faepa-entry__block--emphasis{border-top:1px solid #0f172a;padding-top:10px;margin-top:8px}
      .apf-faepa-entry__block--payment{border-top:1px solid #000}
      .apf-faepa-entry__block dl{display:grid;grid-template-columns: minmax(140px,1fr) 2fr;gap:6px 12px;margin:6px 0 0}
      .apf-faepa-entry__block dt{font-size:12px;color:#475467;padding-bottom:6px;border-bottom:1px solid #e4e7ec}
      .apf-faepa-entry__block dd{margin:0;font-size:13px;color:#0f172a;word-break:break-word;padding-bottom:6px;border-bottom:1px solid #e4e7ec}
      .apf-faepa-entry__block dl > dt:last-of-type,
      .apf-faepa-entry__block dl > dd:last-of-type{border-bottom:none;padding-bottom:0}
      .apf-faepa-pay__note{margin:4px 0 0;font-size:13px;color:#0f172a}
      .apf-faepa-approve__actions{display:flex;flex-direction:column;align-items:flex-start;gap:6px;margin:10px 0}
      .apf-faepa-approve__btn{border:none;border-radius:10px;background:#22c55e;color:#0f172a;font-weight:700;font-size:13px;padding:10px 14px;cursor:pointer;box-shadow:0 8px 18px rgba(34,197,94,.25);transition:background .15s ease, box-shadow .15s ease}
      .apf-faepa-approve__btn:hover{background:#16a34a;box-shadow:0 10px 22px rgba(22,163,74,.25)}
      .apf-faepa-approve__btn.is-hidden{display:none}
      .apf-faepa-approve__hint{margin:0;font-size:12px;color:#475467}
      .apf-faepa-approve__status{display:none;font-size:12px;font-weight:700;color:#166534;background:#ecfdf3;border:1px solid #bbf7d0;border-radius:999px;padding:4px 10px;margin:4px 0 0}
      .apf-faepa-approve__status.is-visible{display:inline-block}
      .apf-faepa-entry--approved .apf-faepa-entry__head strong{color:#166534}
      .apf-faepa-payment-inline.is-hidden{display:none}
      .apf-faepa-notify{display:grid;gap:8px;margin:10px 0}
      .apf-faepa-notify__btn{border:none;border-radius:10px;background:#15803d;color:#fff;font-weight:700;padding:10px 14px;font-size:13px;cursor:pointer;box-shadow:0 10px 18px rgba(21,128,61,.22);transition:background .15s ease, box-shadow .15s ease}
      .apf-faepa-notify__btn:hover{background:#166534;box-shadow:0 12px 22px rgba(22,101,52,.25)}
      .apf-faepa-notify__btn.is-disabled{opacity:.5;cursor:not-allowed}
      .apf-faepa-return__note--muted{background:#f8fafc;border-color:#e2e8f0;color:#475467}
      .apf-faepa-entry__admin{font-size:13px;color:#0f172a;font-weight:700;text-decoration:none;margin-top:6px;display:inline-flex;gap:6px;align-items:center}
      .apf-faepa-entry__admin:hover{text-decoration:underline}
      .apf-faepa-modal{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:20px;z-index:2000;opacity:0;pointer-events:none;transition:opacity .18s ease}
      .apf-faepa-modal[aria-hidden="false"]{opacity:1;pointer-events:auto}
      .apf-faepa-modal__overlay{position:absolute;inset:0;background:rgba(15,23,42,.48)}
      .is-layout-constrained > .apf-faepa-modal{
        max-width:none;
        width:100vw;
        margin-left:0 !important;
        margin-right:0 !important;
      }
      .apf-faepa-modal__overlay{
        position:fixed;
        inset:0;
        width:100vw;
        height:100vh;
      }
      .apf-faepa-modal__dialog{position:relative;z-index:1;background:#fff;border-radius:18px;max-width:820px;width:100%;box-shadow:0 28px 56px rgba(15,23,42,.32);padding:20px}
      .apf-faepa-modal__head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
      .apf-faepa-modal__head h4{margin:4px 0 0;font-size:20px;color:#0f172a}
      .apf-faepa-modal__close{border:none;background:#0f172a;color:#fff;width:36px;height:36px;border-radius:10px;cursor:pointer;font-size:20px;display:inline-flex;align-items:center;justify-content:center}
      .apf-faepa-modal__close:hover{background:#0b1426}
      .apf-faepa-modal__content{max-height:70vh;overflow:auto;padding-right:6px}
      .apf-faepa-modal__empty{margin:8px 0 0;color:#475467;font-size:14px}
      .apf-faepa-modal__list{display:flex;flex-direction:column;gap:10px}
      .apf-faepa-accordion{border:1px solid #e4e7ec;border-radius:14px;overflow:hidden;background:#f8fafc}
      .apf-faepa-accordion__toggle{width:100%;text-align:left;border:none;background:linear-gradient(120deg,#e0f2fe,#f8fafc);padding:12px 14px;font-size:15px;font-weight:700;color:#0f172a;display:flex;justify-content:space-between;align-items:center;gap:12px;cursor:pointer}
      .apf-faepa-accordion__chips{display:flex;gap:8px;flex-wrap:wrap;font-size:12px}
      .apf-faepa-chip{padding:4px 8px;border-radius:999px;font-weight:700}
      .apf-faepa-chip--providers{background:rgba(14,165,233,.12);color:#075985}
      .apf-faepa-chip--coordinators{background:rgba(37,99,235,.12);color:#1d4ed8}
      .apf-faepa-accordion__chevron{margin-left:auto;font-size:18px;transition:transform .15s ease}
      .apf-faepa-accordion__panel{padding:12px 14px;display:flex;flex-direction:column;gap:10px;background:#fff;border-top:1px solid #e4e7ec}
      .apf-faepa-accordion__panel[hidden]{display:none}
      .apf-faepa-accordion__meta{font-size:13px;color:#475467;display:flex;gap:10px;flex-wrap:wrap}
      .apf-faepa-recipient-list{list-style:none;margin:6px 0 0;padding:0;display:flex;flex-direction:column;gap:8px}
      .apf-faepa-recipient{padding:8px 10px;border:1px dashed #d7dde5;border-radius:10px;background:#f9fbfd}
      .apf-faepa-recipient__name{font-weight:700;color:#0f172a}
      .apf-faepa-recipient__meta{font-size:12px;color:#475467;margin-top:4px}
      .apf-faepa-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;background:#ecfeff;color:#0f172a;font-size:12px;font-weight:700}
      @media(max-width:1100px){
        .apf-faepa-return__table-head,
        .apf-faepa-return__row summary{grid-template-columns:minmax(150px,1.5fr) repeat(3,minmax(120px,1fr)) minmax(100px,1fr) 70px}
      }
      @media(max-width:960px){
        .apf-faepa-calendar__header h4{font-size:16px}
        .apf-faepa-calendar__btn{width:32px;height:32px}
        .apf-faepa-calendar__weekdays,
        .apf-faepa-calendar__days{grid-template-columns:repeat(7,minmax(32px,1fr))}
        .apf-faepa-calendar__day{height:48px;font-size:14px}
        .apf-faepa-return__table-head,
        .apf-faepa-return__row summary{grid-template-columns:minmax(150px,1.5fr) repeat(2,minmax(120px,1fr)) minmax(110px,1fr) minmax(90px,1fr) 64px}
        .apf-faepa__hero{text-align:center}
        .apf-faepa__hero div{display:flex;flex-direction:column;gap:6px;align-items:center}
      }
      @media(max-width:720px){
        .apf-pager-row{justify-content:center}
        .apf-pager-row__right{justify-content:center;width:100%}
        .apf-faepa-calendar__tabs{grid-template-columns:repeat(auto-fit,minmax(120px,1fr))}
        .apf-faepa-calendar__weekdays,
        .apf-faepa-calendar__days{grid-template-columns:repeat(7,minmax(30px,1fr))}
        .apf-faepa-calendar__day{height:48px;font-size:14px}
        .apf-faepa-modal__dialog{padding:16px}
        .apf-table-scroller{border:none;box-shadow:none;background:transparent}
        .apf-faepa-return__table{background:transparent}
        .apf-faepa-return__table-head{display:none}
        .apf-faepa-return__row{border:1px solid #000;border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 10px 20px rgba(15,23,42,.06);margin-bottom:12px}
        .apf-faepa-return__row:last-of-type{margin-bottom:0}
        .apf-faepa-return__head{flex-direction:column;align-items:flex-start;text-align:left}
        .apf-faepa-return__row summary{display:flex;flex-direction:column;gap:10px;align-items:flex-start;padding:12px;text-align:left}
        .apf-faepa-return__cell{display:flex;flex-direction:column;align-items:flex-start;gap:4px;font-size:13px;color:#0f172a;width:100%;text-align:left}
        .apf-faepa-return__cell--title{align-items:center;text-align:center}
        .apf-faepa-return__cell--title strong{width:100%;text-align:center}
        .apf-faepa-return__title-full{display:none}
        .apf-faepa-return__title-short{display:inline}
        .apf-faepa-return__cell::before{content:attr(data-label);font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
        .apf-faepa-return__cell--title strong{font-size:15px}
        .apf-faepa-return__value--course{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;text-overflow:ellipsis;max-width:100%}
        .apf-faepa-return__row-details{padding:12px;background:#f8fafc}
        .apf-faepa-entry__head{flex-direction:column;align-items:center;text-align:center;gap:6px}
        .apf-faepa-return__list .apf-faepa-entry__head{justify-content:center;align-items:center}
        .apf-faepa-return__list .apf-faepa-entry__head > div{display:flex;flex-direction:column;align-items:center}
        .apf-faepa-return__list .apf-faepa-entry__title-line{justify-content:center}
        .apf-faepa-return__list .apf-faepa-pill{margin:0 auto}
        .apf-faepa-entry__body{text-align:center}
        .apf-faepa-entry__title-line{flex-direction:column;align-items:center;gap:2px;text-align:center}
        .apf-faepa-entry__separator{display:none}
        .apf-faepa-entry__meta{flex-direction:column;align-items:center;gap:6px;text-align:center}
        .apf-faepa-entry__note,
        .apf-faepa-pay__note{text-align:center}
        .apf-faepa-entry__block{text-align:center}
        .apf-faepa-entry__block dl{grid-template-columns:1fr;gap:4px}
        .apf-faepa-entry__block dt{padding-bottom:0;border-bottom:none;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;text-align:center}
        .apf-faepa-entry__block dd{padding-bottom:8px}
        .apf-faepa-entry__block dl > dd:last-of-type{padding-bottom:0;border-bottom:none}
        .apf-faepa{margin:16px auto}
      }
      @media(max-width:540px){
        .apf-faepa-calendar__weekdays,
        .apf-faepa-calendar__days{grid-template-columns:repeat(7,minmax(26px,1fr))}
        .apf-faepa-calendar__day{height:44px}
        .apf-faepa-calendar__markers{left:50%;transform:translateX(-50%);gap:4px}
        .apf-faepa-calendar__marker{width:8px;height:8px}
        .apf-faepa-calendar__legend{justify-content:center;text-align:center}
        .apf-faepa-modal{padding:12px}
      }
      @media(max-width:375px){
        .apf-faepa-calendar{padding:10px;box-sizing:border-box}
        .apf-faepa-calendar__tabs{grid-template-columns:repeat(auto-fit,minmax(100px,1fr))}
        .apf-faepa-calendar__tab{padding:8px 10px;font-size:12px}
        .apf-faepa-calendar__header{gap:8px}
        .apf-faepa-calendar__header h4{font-size:14px}
        .apf-faepa-calendar__btn{width:28px;height:28px;border-radius:8px}
        .apf-faepa-calendar__weekdays,
        .apf-faepa-calendar__days{grid-template-columns:repeat(7,minmax(24px,1fr));gap:4px}
        .apf-faepa-calendar__weekday{font-size:10px}
        .apf-faepa-calendar__day{height:38px;font-size:12px;border-radius:10px}
        .apf-faepa-calendar__markers{left:50%;bottom:4px;gap:3px}
        .apf-faepa-calendar__marker{width:7px;height:7px}
        .apf-faepa-calendar__legend{font-size:11px;gap:8px}
      }
    </style>

    <script>
    (function(){
      const calendarNode = document.getElementById('apfFaepaCalendar');
      const modal = document.getElementById('apfFaepaModal');
      const modalList = modal ? modal.querySelector('.apf-faepa-modal__list') : null;
      const modalEmpty = modal ? modal.querySelector('.apf-faepa-modal__empty') : null;
      const modalTitle = modal ? modal.querySelector('#apfFaepaModalTitle') : null;
      const modalClose = modal ? modal.querySelectorAll('[data-modal-close]') : [];
      let modalLastFocus = null;

      if(!calendarNode){ return; }
      const groupTabs = calendarNode.querySelectorAll('[data-faepa-group]');
      let activeGroup = 'providers';
      groupTabs.forEach(btn=>{
        if(btn.classList.contains('is-active')){
          activeGroup = btn.getAttribute('data-faepa-group') === 'coordinators' ? 'coordinators' : 'providers';
        }
      });

      const MONTH_NAMES = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
      const WEEKDAYS = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
      const GROUP_LABELS = { providers: 'Colaboradores', coordinators: 'Coordenadores' };

      const eventsByDate = new Map();
      try {
        const raw = JSON.parse(calendarNode.getAttribute('data-events') || '[]');
        if(Array.isArray(raw)){
          raw.forEach(evt=>{
            if(!evt || !evt.date){ return; }
            const date = (evt.date || '').toString();
            const groups = Array.isArray(evt.groups) ? evt.groups.map(g => (g === 'coordinators') ? 'coordinators' : 'providers') : [];
            const normalizedGroups = Array.from(new Set(groups.length ? groups : ['providers']));
            const entries = Array.isArray(evt.events) ? evt.events : [];
            entries.forEach(entry=>{
              if(!entry || !entry.title){ return; }
              entry.groups = Array.isArray(entry.groups) && entry.groups.length
                ? Array.from(new Set(entry.groups.map(g => (g === 'coordinators') ? 'coordinators' : 'providers')))
                : normalizedGroups;
              entry.recipients = entry.recipients && typeof entry.recipients === 'object'
                ? entry.recipients
                : { providers: [], coordinators: [] };
              if(!Array.isArray(entry.recipients.providers)){ entry.recipients.providers = []; }
              if(!Array.isArray(entry.recipients.coordinators)){ entry.recipients.coordinators = []; }
            });
            const payload = {
              date,
              groups: normalizedGroups,
              titles: evt.titles || {},
              events: entries
            };
            eventsByDate.set(date, payload);
          });
        }
      } catch(_e){}

      let monthDate = new Date();
      monthDate.setDate(1);

      const body = calendarNode.querySelector('.apf-faepa-calendar__body');

      function formatDateBr(iso){
        if(!iso || !/^\\d{4}-\\d{2}-\\d{2}$/.test(iso)){ return iso || '—'; }
        return iso.slice(8,10) + '/' + iso.slice(5,7) + '/' + iso.slice(0,4);
      }

      function openModal(date){
        if(!modal || !modalList || !modalEmpty){ return; }
        const info = getInfoForDate(date);
        modalList.innerHTML = '';
        if(modalTitle){ modalTitle.textContent = 'Avisos de ' + formatDateBr(date); }

        const entries = info && Array.isArray(info.events) ? info.events : [];
        if(!entries.length){
          modalEmpty.hidden = false;
        }else{
          modalEmpty.hidden = true;
          entries.forEach((entry, idx)=>{
            const card = document.createElement('article');
            card.className = 'apf-faepa-accordion';

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'apf-faepa-accordion__toggle';
            toggle.setAttribute('aria-expanded','false');
            const panelId = 'apfFaepaPanel-' + date.replace(/[^0-9]/g,'') + '-' + idx;
            toggle.setAttribute('aria-controls', panelId);

            const titleSpan = document.createElement('span');
            titleSpan.textContent = entry.title || 'Aviso';

            const chips = document.createElement('span');
            chips.className = 'apf-faepa-accordion__chips';
            (entry.groups || []).forEach(group=>{
              const chip = document.createElement('span');
              chip.className = 'apf-faepa-chip apf-faepa-chip--' + (group === 'coordinators' ? 'coordinators' : 'providers');
              chip.textContent = GROUP_LABELS[group === 'coordinators' ? 'coordinators' : 'providers'];
              chips.appendChild(chip);
            });

            const chevron = document.createElement('span');
            chevron.className = 'apf-faepa-accordion__chevron';
            chevron.innerHTML = '&#9662;';

            toggle.appendChild(titleSpan);
            toggle.appendChild(chips);
            toggle.appendChild(chevron);

            const panel = document.createElement('div');
            panel.id = panelId;
            panel.className = 'apf-faepa-accordion__panel';
            panel.hidden = true;

            const metaRow = document.createElement('div');
            metaRow.className = 'apf-faepa-accordion__meta';
            metaRow.innerHTML = '<strong>Público:</strong> ' + ((entry.groups && entry.groups.length)
              ? entry.groups.map(g => GROUP_LABELS[g === 'coordinators' ? 'coordinators' : 'providers']).join(', ')
              : '—');
            panel.appendChild(metaRow);

            const providers = Array.isArray(entry.recipients && entry.recipients.providers) ? entry.recipients.providers : [];
            const coordinators = Array.isArray(entry.recipients && entry.recipients.coordinators) ? entry.recipients.coordinators : [];

            if(providers.length){
              const responsible = Array.from(new Set(providers.map(p => (p.director || '').toString().trim()).filter(Boolean)));
              if(responsible.length){
                const badge = document.createElement('div');
                badge.className = 'apf-faepa-badge';
                badge.textContent = 'Coordenadores responsáveis: ' + responsible.join(', ');
                panel.appendChild(badge);
              }
              const label = document.createElement('div');
              label.className = 'apf-faepa-accordion__meta';
              label.innerHTML = '<strong>Colaboradores (' + providers.length + '):</strong>';
              panel.appendChild(label);

              const list = document.createElement('ul');
              list.className = 'apf-faepa-recipient-list';
              providers.forEach(rec=>{
                const item = document.createElement('li');
                item.className = 'apf-faepa-recipient';
                const name = document.createElement('div');
                name.className = 'apf-faepa-recipient__name';
                name.textContent = rec.display || rec.name || rec.email || 'Sem nome';
                item.appendChild(name);
                const meta = document.createElement('div');
                meta.className = 'apf-faepa-recipient__meta';
                const parts = [];
                if(rec.email){ parts.push(rec.email); }
                if(rec.course){ parts.push(rec.course); }
                if(rec.director){ parts.push('Coord.: ' + rec.director); }
                meta.textContent = parts.length ? parts.join(' • ') : 'Dados adicionais não informados';
                item.appendChild(meta);
                list.appendChild(item);
              });
              panel.appendChild(list);
            }

            if(coordinators.length){
              const label = document.createElement('div');
              label.className = 'apf-faepa-accordion__meta';
              label.innerHTML = '<strong>Coordenadores (' + coordinators.length + '):</strong>';
              panel.appendChild(label);

              const list = document.createElement('ul');
              list.className = 'apf-faepa-recipient-list';
              coordinators.forEach(rec=>{
                const item = document.createElement('li');
                item.className = 'apf-faepa-recipient';
                const name = document.createElement('div');
                name.className = 'apf-faepa-recipient__name';
                name.textContent = rec.display || rec.name || rec.email || 'Sem nome';
                item.appendChild(name);
                const meta = document.createElement('div');
                meta.className = 'apf-faepa-recipient__meta';
                const parts = [];
                if(rec.email){ parts.push(rec.email); }
                if(rec.course){ parts.push(rec.course); }
                meta.textContent = parts.length ? parts.join(' • ') : 'Dados adicionais não informados';
                item.appendChild(meta);
                list.appendChild(item);
              });
              panel.appendChild(list);
            }

            if(!providers.length && !coordinators.length){
              const fallback = document.createElement('div');
              fallback.className = 'apf-faepa-accordion__meta';
              fallback.textContent = 'Destinatários não informados.';
              panel.appendChild(fallback);
            }

            toggle.addEventListener('click', ()=>{
              const expanded = toggle.getAttribute('aria-expanded') === 'true';
              toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
              panel.hidden = expanded;
              chevron.style.transform = expanded ? 'rotate(0deg)' : 'rotate(180deg)';
            });

            card.appendChild(toggle);
            card.appendChild(panel);
            modalList.appendChild(card);
          });
        }

        modalLastFocus = document.activeElement;
        modal.setAttribute('aria-hidden','false');
        document.body.style.overflow = 'hidden';
        const focusTarget = modal.querySelector('.apf-faepa-modal__close') || modal;
        if(focusTarget){ focusTarget.focus(); }
      }

      function closeModal(){
        if(!modal){ return; }
        modal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        if(modalLastFocus){ modalLastFocus.focus(); }
      }

      if(modalClose.length){
        modalClose.forEach(btn=>{
          btn.addEventListener('click', closeModal);
        });
      }
      if(modal){
        modal.addEventListener('click', (e)=>{
          if(e.target && e.target.hasAttribute && e.target.hasAttribute('data-modal-close')){
            closeModal();
          }
        });
        modal.addEventListener('keydown', (e)=>{
          if(e.key === 'Escape'){
            e.preventDefault();
            closeModal();
          }
        });
      }
      document.addEventListener('keydown', (e)=>{
        if(e.key === 'Escape' && modal && modal.getAttribute('aria-hidden') === 'false'){
          e.preventDefault();
          closeModal();
        }
      });

      function buildTooltip(info){
        if(!info || !info.titles){ return ''; }
        const parts = [];
        ['providers','coordinators'].forEach(group=>{
          const list = Array.isArray(info.titles[group]) ? info.titles[group].filter(Boolean) : [];
          if(list.length){
            parts.push((GROUP_LABELS[group] || group) + ': ' + list.join('; '));
          }
        });
        return parts.join(' | ');
      }

      function getInfoForDate(date){
        const info = eventsByDate.get(date);
        if(!info){ return null; }
        const entries = Array.isArray(info.events) ? info.events.filter(entry=>{
          const groups = Array.isArray(entry.groups) && entry.groups.length
            ? entry.groups
            : ['providers'];
          return groups.includes(activeGroup);
        }).map(entry=>{
          const clone = Object.assign({}, entry);
          const entryGroups = Array.isArray(entry.groups) && entry.groups.length ? entry.groups : ['providers'];
          clone.groups = entryGroups.includes(activeGroup) ? [activeGroup] : [activeGroup];
          return clone;
        }) : [];
        if(!entries.length){ return null; }
        const titles = {};
        if(info.titles && Array.isArray(info.titles[activeGroup])){
          titles[activeGroup] = info.titles[activeGroup];
        }
        return {
          date: info.date,
          groups: [activeGroup],
          titles: titles,
          events: entries,
        };
      }

      function renderCalendar(){
        if(!body){ return; }
        const container = document.createElement('div');
        container.className = 'apf-faepa-calendar__inner';

        const header = document.createElement('div');
        header.className = 'apf-faepa-calendar__header';

        const title = document.createElement('h4');
        const monthIndex = monthDate.getMonth();
        const year = monthDate.getFullYear();
        title.textContent = MONTH_NAMES[monthIndex] + ' ' + year;

        const nav = document.createElement('div');
        nav.className = 'apf-faepa-calendar__nav';

        const prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'apf-faepa-calendar__btn';
        prev.innerHTML = '&larr;';
        prev.addEventListener('click', ()=>{
          monthDate.setMonth(monthDate.getMonth() - 1, 1);
          renderCalendar();
        });

        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'apf-faepa-calendar__btn';
        next.innerHTML = '&rarr;';
        next.addEventListener('click', ()=>{
          monthDate.setMonth(monthDate.getMonth() + 1, 1);
          renderCalendar();
        });

        nav.appendChild(prev);
        nav.appendChild(next);
        header.appendChild(title);
        header.appendChild(nav);

        const weekRow = document.createElement('div');
        weekRow.className = 'apf-faepa-calendar__weekdays';
        WEEKDAYS.forEach(day=>{
          const span = document.createElement('span');
          span.className = 'apf-faepa-calendar__weekday';
          span.textContent = day;
          weekRow.appendChild(span);
        });

        const daysGrid = document.createElement('div');
        daysGrid.className = 'apf-faepa-calendar__days';
        const firstWeekday = monthDate.getDay();
        const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
        const totalCells = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;

        for(let cell=0; cell<totalCells; cell++){
          const dayNumber = cell - firstWeekday + 1;
          const div = document.createElement('div');
          div.className = 'apf-faepa-calendar__day';

          if(dayNumber < 1 || dayNumber > daysInMonth){
            div.classList.add('apf-faepa-calendar__day--muted');
          }else{
            const iso = year + '-' + String(monthIndex + 1).padStart(2,'0') + '-' + String(dayNumber).padStart(2,'0');
            div.textContent = String(dayNumber);
            if(eventsByDate.has(iso)){
              const info = getInfoForDate(iso);
              if(!info){
                daysGrid.appendChild(div);
                continue;
              }
              div.classList.add('apf-faepa-calendar__day--has-event');
              const hasProviders = (info.groups || []).includes('providers');
              const hasCoordinators = (info.groups || []).includes('coordinators');
              if(hasProviders && hasCoordinators){
                div.classList.add('apf-faepa-calendar__day--group-mixed');
              }else if(hasCoordinators){
                div.classList.add('apf-faepa-calendar__day--group-coordinators');
              }else{
                div.classList.add('apf-faepa-calendar__day--group-providers');
              }

              const markers = document.createElement('div');
              markers.className = 'apf-faepa-calendar__markers';
              (info.groups || []).forEach(group=>{
                const marker = document.createElement('span');
                marker.className = 'apf-faepa-calendar__marker apf-faepa-calendar__marker--' + (group === 'coordinators' ? 'coordinators' : 'providers');
                marker.title = GROUP_LABELS[group === 'coordinators' ? 'coordinators' : 'providers'] || '';
                markers.appendChild(marker);
              });
              div.appendChild(markers);

              const tooltip = buildTooltip(info);
              if(tooltip){ div.title = tooltip; }

              div.setAttribute('role','button');
              div.setAttribute('tabindex','0');
              div.setAttribute('aria-label','Ver avisos de ' + formatDateBr(iso));
              div.addEventListener('click', ()=>openModal(iso));
              div.addEventListener('keydown', (ev)=>{
                if(ev.key === 'Enter' || ev.key === ' '){
                  ev.preventDefault();
                  openModal(iso);
                }
              });
            }
          }

          daysGrid.appendChild(div);
        }

        container.appendChild(header);
        container.appendChild(weekRow);
        container.appendChild(daysGrid);
        body.innerHTML = '';
        body.appendChild(container);
      }

      groupTabs.forEach(tab=>{
        tab.addEventListener('click', ()=>{
          activeGroup = tab.getAttribute('data-faepa-group') === 'coordinators' ? 'coordinators' : 'providers';
          groupTabs.forEach(btn=>{
            btn.classList.toggle('is-active', btn === tab);
            btn.setAttribute('aria-pressed', btn === tab ? 'true' : 'false');
          });
          renderCalendar();
        });
      });

      renderCalendar();
    })();
    </script>
    <?php
        return ob_get_clean();
    }
}

add_shortcode( 'apf_portal_faepa', 'apf_render_portal_faepa' );
add_shortcode( 'portal_faepa', 'apf_render_portal_faepa' );
