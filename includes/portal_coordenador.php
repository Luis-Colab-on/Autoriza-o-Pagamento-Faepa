<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'apf_coordinator_has_portal_access' ) ) {
    /**
     * Verifica se o coordenador já foi aprovado e possui curso vinculado.
     *
     * @param int $user_id
     * @return bool
     */
    function apf_coordinator_has_portal_access( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return false;
        }

        if ( function_exists( 'apf_get_directors_list' ) ) {
            $directors = apf_get_directors_list();
        } else {
            $directors = get_option( 'apf_directors', array() );
            if ( ! is_array( $directors ) ) {
                $directors = array();
            }
        }
        $directors = apf_normalize_directors_list( $directors );

        $user        = get_user_by( 'id', $user_id );
        $user_email  = $user && $user->user_email ? sanitize_email( $user->user_email ) : '';
        $user_email  = $user_email ? strtolower( $user_email ) : '';

        foreach ( $directors as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $status = isset( $entry['status'] ) ? strtolower( trim( (string) $entry['status'] ) ) : '';
            if ( 'approved' !== $status ) {
                continue;
            }
            $course = isset( $entry['course'] ) ? trim( (string) $entry['course'] ) : '';
            if ( '' === $course ) {
                continue;
            }

            $entry_user_id = isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0;
            $entry_email   = isset( $entry['email'] ) ? sanitize_email( $entry['email'] ) : '';
            $entry_email   = $entry_email ? strtolower( $entry_email ) : '';

            if ( $entry_user_id > 0 && $entry_user_id === $user_id ) {
                return true;
            }

            if ( $entry_email && $user_email && $entry_email === $user_email ) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Shortcode: [apf_portal_coordenador]
 * Área simplificada para coordenadores sugerirem/atualizarem seu vínculo com um curso.
 */
add_shortcode( 'apf_portal_coordenador', function () {
    if ( ! is_user_logged_in() ) {
        $redirect = isset( $_SERVER['REQUEST_URI'] ) ? esc_url( $_SERVER['REQUEST_URI'] ) : home_url();
        return apf_render_login_card( array(
            'redirect'    => $redirect,
            'title'       => 'Portal do Coordenador',
            'description' => 'Entre com sua conta FAEPA para indicar o curso pelo qual é responsável.',
        ) );
    }

    $user        = wp_get_current_user();
    $user_id     = get_current_user_id();
    $user_name   = $user && $user->display_name ? $user->display_name : $user->user_login;
    $user_email  = $user && $user->user_email ? sanitize_email( $user->user_email ) : '';
    $coord_alias_email = apf_get_user_channel_email( $user_id, 'coordinator' );
    $directors   = get_option( 'apf_directors', array() );
    if ( ! is_array( $directors ) ) {
        $directors = array();
    }
    $directors = apf_normalize_directors_list( $directors );

    $find_entry = function () use ( &$directors, $user_id, $user_email ) {
        foreach ( $directors as $idx => $entry ) {
            if ( isset( $entry['user_id'] ) && (int) $entry['user_id'] === $user_id ) {
                return array( $idx, $entry );
            }
        }
        if ( $user_email !== '' ) {
            foreach ( $directors as $idx => $entry ) {
                if ( isset( $entry['email'] ) && strtolower( $entry['email'] ) === strtolower( $user_email ) ) {
                    return array( $idx, $entry );
                }
            }
        }
        return array( null, null );
    };

    list( $existing_index, $existing_entry ) = $find_entry();

    $notice      = '';
    $notice_type = 'success';
    if ( isset( $_GET['apf_coord_notice'] ) ) {
        $notice = sanitize_text_field( wp_unslash( $_GET['apf_coord_notice'] ) );
        if ( isset( $_GET['apf_coord_status'] ) && 'error' === sanitize_text_field( wp_unslash( $_GET['apf_coord_status'] ) ) ) {
            $notice_type = 'error';
        }
    }
    $request_notice      = '';
    $request_notice_type = 'success';
    if ( isset( $_GET['apf_coord_req_notice'] ) ) {
        $request_notice = sanitize_text_field( wp_unslash( $_GET['apf_coord_req_notice'] ) );
        if ( isset( $_GET['apf_coord_req_status'] ) && 'error' === sanitize_text_field( wp_unslash( $_GET['apf_coord_req_status'] ) ) ) {
            $request_notice_type = 'error';
        }
    }
    $build_collab_token = function( $email, $course, $dir_key ) {
        $email = strtolower( trim( sanitize_email( (string) $email ) ) );
        $course = strtolower( trim( sanitize_text_field( (string) $course ) ) );
        $dir_key = strtolower( trim( sanitize_text_field( (string) $dir_key ) ) );
        return 'collab_' . md5( $email . '|' . $course . '|' . $dir_key );
    };
    $alert_notice      = '';
    $alert_notice_type = 'success';
    if ( isset( $_GET['apf_coord_alert_notice'] ) ) {
        $alert_notice = sanitize_text_field( wp_unslash( $_GET['apf_coord_alert_notice'] ) );
        if ( isset( $_GET['apf_coord_alert_status'] ) && 'error' === sanitize_text_field( wp_unslash( $_GET['apf_coord_alert_status'] ) ) ) {
            $alert_notice_type = 'error';
        }
    }

    if ( ! empty( $_POST['apf_coord_submit'] ) ) {
        if ( ! isset( $_POST['apf_coord_nonce'] ) || ! wp_verify_nonce( $_POST['apf_coord_nonce'], 'apf_coord_portal' ) ) {
            $notice      = 'Falha de segurança. Recarregue a página e tente novamente.';
            $notice_type = 'error';
        } else {
            $name    = isset( $_POST['apf_coord_name'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_coord_name'] ) ) : '';
            $email   = isset( $_POST['apf_coord_email'] ) ? sanitize_email( wp_unslash( $_POST['apf_coord_email'] ) ) : $user_email;
            $course  = isset( $_POST['apf_coord_course'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_coord_course'] ) ) : '';
            $course_manual = isset( $_POST['apf_coord_course_manual'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_coord_course_manual'] ) ) : '';
            $requested_course = ( '__manual__' === $course ) ? $course_manual : $course;

            $can_proceed = true;
            if ( '' === $name ) {
                $notice      = 'Informe seu nome completo.';
                $notice_type = 'error';
                $can_proceed = false;
            }

            if ( '' === $email && $user_email ) {
                $email = $user_email;
            }
            if ( $email ) {
                apf_set_user_channel_email( $user_id, 'coordinator', $email );
            }

            if ( null === $existing_index || ! isset( $directors[ $existing_index ] ) ) {
                // tenta localizar novamente por nome+curso para evitar duplicar
                foreach ( $directors as $idx => $entry ) {
                    $dir_name   = isset( $entry['director'] ) ? strtolower( trim( $entry['director'] ) ) : '';
                    $dir_course = isset( $entry['course'] ) ? strtolower( trim( $entry['course'] ) ) : '';
                    if ( $dir_name === strtolower( $name ) && $dir_course === strtolower( $requested_course ) ) {
                        $existing_index = $idx;
                        break;
                    }
                }
            }

            $current_entry = ( null !== $existing_index && isset( $directors[ $existing_index ] ) ) ? $directors[ $existing_index ] : null;
            $current_status = isset( $current_entry['status'] ) ? strtolower( (string) $current_entry['status'] ) : '';
            $current_course = isset( $current_entry['course'] ) ? (string) $current_entry['course'] : '';
            $course_locked  = ( 'approved' === $current_status && '' !== trim( $current_course ) );

            if ( $course_locked ) {
                if ( '' !== $requested_course && strcasecmp( $requested_course, $current_course ) !== 0 ) {
                    $notice      = 'Seu curso aprovado só pode ser alterado pelo financeiro.';
                    $notice_type = 'error';
                    $can_proceed = false;
                }
                $requested_course = $current_course;
            } elseif ( '' === $requested_course ) {
                $notice      = 'Selecione o curso pelo qual é responsável.';
                $notice_type = 'error';
                $can_proceed = false;
            }

            if ( $can_proceed ) {
                $timestamp = time();
                $record_id = isset( $current_entry['id'] ) ? $current_entry['id'] : uniqid( 'dir_' );
                $success_message = $course_locked
                    ? 'Dados atualizados com sucesso.'
                    : 'Dados enviados com sucesso. Aguarde a confirmação do financeiro.';

                if ( $course_locked && null !== $current_entry ) {
                    $replacement = $current_entry;
                    $replacement['id']        = $record_id;
                    $replacement['course']    = $current_course;
                    $replacement['director']  = $name;
                    $replacement['email']     = $email;
                    $replacement['user_id']   = $user_id;
                    $replacement['source']    = 'coordinator_portal';
                    $replacement['updated_at']= $timestamp;
                    $replacement['status']    = 'approved';
                    if ( ! isset( $replacement['status_updated_at'] ) ) {
                        $replacement['status_updated_at'] = $timestamp;
                    }
                    if ( ! isset( $replacement['status_updated_by'] ) ) {
                        $replacement['status_updated_by'] = get_current_user_id();
                    }
                    if ( ! isset( $replacement['requested_at'] ) ) {
                        $replacement['requested_at'] = $timestamp;
                    }
                    $directors[ $existing_index ] = $replacement;
                } else {
                    $payload = array(
                        'id'                  => $record_id,
                        'course'              => $requested_course,
                        'director'            => $name,
                        'user_id'             => $user_id,
                        'email'               => $email,
                        'source'              => 'coordinator_portal',
                        'updated_at'          => $timestamp,
                        'status'              => 'pending',
                        'status_updated_at'   => $timestamp,
                        'status_updated_by'   => $user_id,
                        'requested_at'        => isset( $current_entry['requested_at'] ) ? $current_entry['requested_at'] : $timestamp,
                    );

                    if ( null !== $existing_index && isset( $directors[ $existing_index ] ) ) {
                        $merged = array_merge( $directors[ $existing_index ], $payload );
                        unset( $merged['approved_at'], $merged['approved_by'], $merged['rejected_at'], $merged['rejected_by'] );
                        $directors[ $existing_index ] = $merged;
                    } else {
                        $directors[] = $payload;
                    }
                }

                update_option( 'apf_directors', array_values( $directors ), false );

                $target = '';
                if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
                    $target = wp_unslash( $_SERVER['REQUEST_URI'] );
                }
                if ( '' === $target ) {
                    $target = home_url( '/' );
                }
                $target = remove_query_arg( array( 'apf_coord_notice', 'apf_coord_status' ), $target );
                if ( strpos( $target, 'http' ) !== 0 && strpos( $target, '/' ) !== 0 ) {
                    $target = '/' . ltrim( $target, '/' );
                }
                $target = add_query_arg( array(
                    'apf_coord_notice' => $success_message,
                    'apf_coord_status' => 'success',
                ), $target );

                wp_safe_redirect( esc_url_raw( $target ) );
                exit;
            }
        }
    }

    if ( empty( $notice ) && isset( $_GET['apf_coord_status'] ) && 'success' === sanitize_text_field( wp_unslash( $_GET['apf_coord_status'] ) ) ) {
        $notice = 'Operação realizada com sucesso.';
    }

    // recarrega dados atualizados
    $directors = get_option( 'apf_directors', array() );
    if ( ! is_array( $directors ) ) {
        $directors = array();
    }
    $directors = apf_normalize_directors_list( $directors );
    list( $existing_index, $existing_entry ) = $find_entry();

    $available_courses = array();
    if ( function_exists( 'apf_inbox_get_available_courses' ) ) {
        $available_courses = apf_inbox_get_available_courses();
    }
    if ( empty( $available_courses ) ) {
        foreach ( $directors as $entry ) {
            if ( ! empty( $entry['course'] ) ) {
                $available_courses[ $entry['course'] ] = true;
            }
        }
        $available_courses = array_keys( $available_courses );
        natcasesort( $available_courses );
        $available_courses = array_values( $available_courses );
    }

    $current_name   = $existing_entry['director'] ?? $user_name;
    $current_email  = $coord_alias_email ?: ( $existing_entry['email'] ?? $user_email );
    $current_course = $existing_entry['course'] ?? '';
    $current_name_normalized   = strtolower( trim( sanitize_text_field( (string) $current_name ) ) );
    $current_course_normalized = strtolower( trim( sanitize_text_field( (string) $current_course ) ) );
    $is_manual_course = $current_course && ! in_array( $current_course, $available_courses, true );

    $current_status = isset( $existing_entry['status'] ) ? $existing_entry['status'] : '';
    if ( '' === $current_status && ! empty( $existing_entry ) ) {
        $current_status = 'approved';
    }
    if ( ! in_array( $current_status, array( 'approved', 'pending', 'rejected' ), true ) ) {
        $current_status = empty( $existing_entry ) ? '' : 'pending';
    }
    $has_portal_access = ( 'approved' === $current_status && ! empty( $current_course ) );
    $coordinator_key = '';
    if ( ! empty( $existing_entry ) ) {
        $key_course = $existing_entry['course'] ?? '';
        if ( function_exists( 'apf_inbox_normalize_course_value' ) ) {
            $key_course = apf_inbox_normalize_course_value( $key_course );
        }
        $coordinator_key = apf_inbox_build_director_key( $existing_entry['director'] ?? '', $key_course );
    }
    $current_email_lower = $current_email ? strtolower( $current_email ) : '';
    $belongs_to_current_request = function( $entry ) use ( $coordinator_key, $existing_entry, $user_id, $current_email_lower ) {
        if ( ! is_array( $entry ) ) {
            return false;
        }
        $target_key   = isset( $entry['coordinator_key'] ) ? (string) $entry['coordinator_key'] : '';
        $target_id    = isset( $entry['coordinator_id'] ) ? (string) $entry['coordinator_id'] : '';
        $target_user  = isset( $entry['coordinator_user_id'] ) ? (int) $entry['coordinator_user_id'] : 0;
        $target_email = isset( $entry['coordinator_email'] ) ? strtolower( sanitize_email( $entry['coordinator_email'] ) ) : '';

        if ( $target_key && $coordinator_key && $target_key === $coordinator_key ) {
            return true;
        }
        if ( $target_id && isset( $existing_entry['id'] ) && $target_id === (string) $existing_entry['id'] ) {
            return true;
        }
        if ( $target_user > 0 && $target_user === $user_id ) {
            return true;
        }
        if ( $target_email && $current_email_lower && $target_email === $current_email_lower ) {
            return true;
        }

        return false;
    };

    if ( class_exists( 'Faepa_Chatbox' ) && function_exists( 'wp_add_inline_script' ) ) {
        if ( ! $has_portal_access ) {
            wp_add_inline_script(
                Faepa_Chatbox::SCRIPT_HANDLE,
                'window.faepaChatboxForceHide = true;',
                'before'
            );
        }
        $ready = $has_portal_access ? 'true' : 'false';
        wp_add_inline_script(
            Faepa_Chatbox::SCRIPT_HANDLE,
            'window.faepaChatboxPortalReady = ' . $ready . '; window.faepaChatboxPortalContext = "coordenador";',
            'before'
        );
    }

    if ( isset( $_POST['apf_coord_batch_submit'] ) ) {
        if ( ! isset( $_POST['apf_coord_batch_nonce'] ) || ! wp_verify_nonce( $_POST['apf_coord_batch_nonce'], 'apf_coord_batch' ) ) {
            $request_notice      = 'Não foi possível enviar o lote ao financeiro. Recarregue a página e tente novamente.';
            $request_notice_type = 'error';
        } elseif ( ! $has_portal_access ) {
            $request_notice      = 'Finalize o cadastro para poder enviar solicitações.';
            $request_notice_type = 'error';
        } else {
            $batch_id = isset( $_POST['apf_coord_batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_coord_batch_id'] ) ) : '';
            if ( '' === $batch_id ) {
                $request_notice      = 'Seleção inválida. Recarregue a página e tente novamente.';
                $request_notice_type = 'error';
            } else {
                $requests = apf_get_coordinator_requests();
                $batch_entries = array();
                if ( is_array( $requests ) ) {
                    foreach ( $requests as $entry ) {
                        if ( ! is_array( $entry ) || ! isset( $entry['batch_id'] ) ) {
                            continue;
                        }
                        if ( $entry['batch_id'] !== $batch_id ) {
                            continue;
                        }
                        if ( ! $belongs_to_current_request( $entry ) ) {
                            $batch_entries = array();
                            break;
                        }
                        $batch_entries[] = $entry;
                    }
                }
                if ( empty( $batch_entries ) ) {
                    $request_notice      = 'Lote não encontrado para este coordenador.';
                    $request_notice_type = 'error';
                } elseif ( ! empty( $batch_entries[0]['batch_submitted'] ) ) {
                    $request_notice      = 'Este lote já foi enviado ao financeiro.';
                    $request_notice_type = 'error';
                } else {
                    $pending = 0;
                    foreach ( $batch_entries as $entry ) {
                        if ( isset( $entry['status'] ) && 'pending' === $entry['status'] ) {
                            $pending++;
                            break;
                        }
                    }
                    if ( $pending > 0 ) {
                        $request_notice      = 'Valide ou recuse todos os colaboradores antes de enviar ao financeiro.';
                        $request_notice_type = 'error';
                    } else {
                        $updated = apf_mark_coordinator_batch_submitted( $batch_id, array(
                            'user_id' => $user_id,
                        ) );
                        if ( $updated ) {
                            $request_notice      = 'Solicitações reenviadas ao financeiro com sucesso.';
                            $request_notice_type = 'success';
                        } else {
                            $request_notice      = 'Não foi possível atualizar este lote. Recarregue a página e tente novamente.';
                            $request_notice_type = 'error';
                        }
                    }
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
        $target = remove_query_arg( array( 'apf_coord_req_notice', 'apf_coord_req_status' ), $target );
        $target = add_query_arg( array(
            'apf_coord_req_notice' => $request_notice,
            'apf_coord_req_status' => ( 'success' === $request_notice_type ) ? 'success' : 'error',
        ), $target );
        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    $is_ajax_request = (
        ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) ) )
        || ( isset( $_POST['apf_coord_ajax'] ) && '1' === (string) $_POST['apf_coord_ajax'] )
    );

    if ( isset( $_POST['apf_coord_request_action'] ) ) {
        if ( ! isset( $_POST['apf_coord_request_nonce'] ) || ! wp_verify_nonce( $_POST['apf_coord_request_nonce'], 'apf_coord_request' ) ) {
            $request_notice      = 'Não foi possível registrar sua resposta. Recarregue e tente novamente.';
            $request_notice_type = 'error';
        } else {
            $action     = sanitize_text_field( wp_unslash( $_POST['apf_coord_request_action'] ) );
            $request_id = isset( $_POST['apf_coord_request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_coord_request_id'] ) ) : '';
            $allowed    = array(
                'approve' => 'approved',
                'reject'  => 'rejected',
                'revert'  => 'pending',
            );
            $note_raw   = isset( $_POST['apf_coord_request_note'] ) ? wp_unslash( $_POST['apf_coord_request_note'] ) : '';
            $note_sanitized = sanitize_textarea_field( $note_raw );
            if ( '' === $note_sanitized && isset( $_POST['apf_coord_request_note_noscript'] ) ) {
                $note_sanitized = sanitize_textarea_field( wp_unslash( $_POST['apf_coord_request_note_noscript'] ) );
            }

            if ( '' === $request_id || ! isset( $allowed[ $action ] ) ) {
                $request_notice      = 'Seleção inválida. Recarregue a página e tente novamente.';
                $request_notice_type = 'error';
            } elseif ( ! $has_portal_access ) {
                $request_notice      = 'Aguarde a aprovação do financeiro para responder solicitações.';
                $request_notice_type = 'error';
            } elseif ( 'reject' === $action && '' === $note_sanitized ) {
                $request_notice      = 'Informe o motivo da recusa antes de enviar.';
                $request_notice_type = 'error';
            } else {
                $requests = apf_get_coordinator_requests();
                $target   = null;
                if ( is_array( $requests ) ) {
                    foreach ( $requests as $entry ) {
                        if ( ! is_array( $entry ) || ! isset( $entry['id'] ) ) {
                            continue;
                        }
                        if ( $entry['id'] === $request_id ) {
                            $target = $entry;
                            break;
                        }
                    }
                }

                if ( empty( $target ) ) {
                    $request_notice      = 'Solicitação não encontrada.';
                    $request_notice_type = 'error';
                } else {
                    if ( ! $belongs_to_current_request( $target ) ) {
                        $request_notice      = 'Esta solicitação não está vinculada ao seu curso.';
                        $request_notice_type = 'error';
                    } elseif ( 'revert' !== $action && isset( $target['status'] ) && 'pending' !== $target['status'] ) {
                        $request_notice      = 'Esta solicitação já foi respondida.';
                        $request_notice_type = 'error';
                    } elseif ( ! empty( $target['batch_submitted'] ) ) {
                        $request_notice      = 'Este lote já foi enviado ao financeiro.';
                        $request_notice_type = 'error';
                    } else {
                        $status = $allowed[ $action ];
                        $extra_payload = array( 'user_id' => $user_id );
                        if ( 'reject' === $action && '' !== $note_sanitized ) {
                            $extra_payload['note'] = $note_sanitized;
                        }
                        $updated = apf_set_coordinator_request_status( $request_id, $status, $extra_payload );
                        if ( $updated ) {
                            $decision_label = date_i18n( 'd/m/Y H:i' );
                            if ( 'pending' === $status ) {
                                $request_notice = 'Solicitação reaberta para edição.';
                            } else {
                                $request_notice = ( 'approved' === $status )
                                    ? 'Solicitação aprovada com sucesso.'
                                    : 'Solicitação recusada.';
                            }
                            $request_notice_type = 'success';
                            if ( $is_ajax_request ) {
                                wp_send_json_success( array(
                                    'request_id'    => $request_id,
                                    'status'        => $status,
                                    'status_label'  => ( 'approved' === $status ) ? 'Aprovado' : ( 'pending' === $status ? 'Pendente' : 'Recusado' ),
                                    'decision_label'=> ( 'pending' === $status ) ? '' : $decision_label,
                                    'note'          => ( 'rejected' === $status ) ? $note_sanitized : '',
                                    'message'       => $request_notice,
                                ) );
                            }
                        } else {
                            $request_notice      = 'Não foi possível atualizar esta solicitação.';
                            $request_notice_type = 'error';
                            if ( $is_ajax_request ) {
                                wp_send_json_error( array(
                                    'message' => $request_notice,
                                ), 400 );
                            }
                        }
                    }
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
        $target = remove_query_arg( array( 'apf_coord_req_notice', 'apf_coord_req_status' ), $target );
        $target = add_query_arg( array(
            'apf_coord_req_notice' => $request_notice,
            'apf_coord_req_status' => ( 'success' === $request_notice_type ) ? 'success' : 'error',
        ), $target );
        if ( $is_ajax_request ) {
            $payload = array( 'message' => $request_notice );
            if ( 'success' === $request_notice_type ) {
                wp_send_json_success( $payload );
            } else {
                wp_send_json_error( $payload, 400 );
            }
        } else {
            wp_safe_redirect( esc_url_raw( $target ) );
            exit;
        }
    }

    $calendar_events = array();
    if ( $has_portal_access && function_exists( 'apf_scheduler_get_events' ) ) {
        // Resumo rápido: calendário agora mistura eventos de colaboradores/coordenadores (ajuste ainda em finalização).
        $events             = apf_scheduler_get_events();
        $coord_email_lower  = $current_email ? strtolower( $current_email ) : '';
        $calendar_events_map = array();

        foreach ( $events as $event ) {
            $date = isset( $event['date'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $event['date'] ) : '';
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                continue;
            }
            $event_id = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';
            $providers_only_faepa = ( '' !== $event_id && strpos( $event_id, 'faepa_pay_' ) === 0 );
            $event_created_by = isset( $event['created_by'] ) ? (int) $event['created_by'] : 0;
            $title = isset( $event['title'] ) ? sanitize_text_field( $event['title'] ) : '';
            if ( '' === $title ) {
                continue;
            }
            if ( empty( $event['recipients'] ) || ! is_array( $event['recipients'] ) ) {
                continue;
            }

            $matched_providers   = false;
            $matched_coordinators = false;
            $providers_display = array();
            $coordinators_display = array();

            $event_target_token = '';
            foreach ( $event['recipients'] as $recipient ) {
                if ( ! is_array( $recipient ) ) {
                    continue;
                }
                $recipient_group = isset( $recipient['group'] ) ? sanitize_key( $recipient['group'] ) : '';
                if ( ! in_array( $recipient_group, array( 'providers', 'coordinators' ), true ) ) {
                    $recipient_group = 'providers';
                }
                if ( $providers_only_faepa && 'coordinators' === $recipient_group ) {
                    // Para avisos FAEPA, não listamos na agenda pessoal do coordenador.
                    continue;
                }
                $recipient_user  = isset( $recipient['user_id'] ) ? (int) $recipient['user_id'] : 0;
                $recipient_email = isset( $recipient['email'] ) ? strtolower( sanitize_email( $recipient['email'] ) ) : '';
                $recipient_name  = isset( $recipient['name'] ) ? sanitize_text_field( $recipient['name'] ) : '';
                $recipient_display = $recipient_name;
                if ( $recipient_email ) {
                    $recipient_display = $recipient_name
                        ? $recipient_name . ' <' . $recipient_email . '>'
                        : $recipient_email;
                }

                if ( 'coordinators' === $recipient_group ) {
                    if ( ( $recipient_user > 0 && $recipient_user === $user_id ) || ( $coord_email_lower && $recipient_email === $coord_email_lower ) ) {
                        $matched_coordinators = true;
                        if ( '' !== $recipient_display ) {
                            $coordinators_display[] = $recipient_display;
                        }
                    }
                    continue;
                }

                if ( 'providers' === $recipient_group ) {
                    $dir_matches = false;
                    $recipient_dir_key = isset( $recipient['director_key'] ) ? sanitize_text_field( $recipient['director_key'] ) : '';
                    $recipient_course  = isset( $recipient['course'] ) ? sanitize_text_field( $recipient['course'] ) : '';
                    $recipient_name_raw = isset( $recipient['director_name'] ) ? sanitize_text_field( $recipient['director_name'] ) : '';
                    $recipient_token = '';
                    if ( ! empty( $recipient['email'] ) ) {
                        $recipient_token = $build_collab_token(
                            sanitize_email( $recipient['email'] ),
                            $recipient_course,
                            $recipient_dir_key
                        );
                    }
                    $recipient_course_norm = strtolower( trim( $recipient_course ) );
                    $recipient_name_norm   = strtolower( trim( $recipient_name_raw ) );

                    if ( $coordinator_key && $recipient_dir_key && $recipient_dir_key === $coordinator_key ) {
                        $dir_matches = true;
                    }

                    if ( ! $dir_matches && $recipient_course_norm && $current_course_normalized && $recipient_course_norm === $current_course_normalized ) {
                        if ( ! $current_name_normalized || ! $recipient_name_norm || $recipient_name_norm === $current_name_normalized ) {
                            $dir_matches = true;
                        }
                    }

                    if ( ! $dir_matches && $recipient_name_norm && $current_name_normalized && $recipient_name_norm === $current_name_normalized ) {
                        $dir_matches = true;
                    }

                    $email_matches = ( $coord_email_lower && $recipient_email === $coord_email_lower );

                    if ( $dir_matches || $email_matches ) {
                        $matched_providers = true;
                        if ( '' !== $recipient_display ) {
                            $providers_display[] = $recipient_display;
                        }
                        if ( '' === $event_target_token && $recipient_token ) {
                            $event_target_token = $recipient_token;
                        }
                    }
                }
            }

            if ( ! $matched_providers && ! $matched_coordinators ) {
                continue;
            }

            if ( ! isset( $calendar_events_map[ $date ] ) ) {
                $calendar_events_map[ $date ] = array(
                    'titles' => array(
                        'providers'    => array(),
                        'coordinators' => array(),
                    ),
                    'events' => array(),
                );
            }

            if ( $matched_providers ) {
                $calendar_events_map[ $date ]['titles']['providers'][] = $title;
            }
            if ( $matched_coordinators ) {
                $calendar_events_map[ $date ]['titles']['coordinators'][] = $title;
            }

            $event_groups = array();
            if ( $matched_providers ) {
                $event_groups[] = 'providers';
            }
            if ( $matched_coordinators ) {
                $event_groups[] = 'coordinators';
            }
            if ( empty( $event_groups ) ) {
                $event_groups[] = 'providers';
            }

            $event_editable = ( strpos( $event_id, 'coord_msg_' ) === 0 && $event_created_by === $user_id && $event_target_token );
            $calendar_events_map[ $date ]['events'][] = array(
                'id'         => $event_id,
                'title'      => $title,
                'editable'   => (bool) $event_editable,
                'target_token' => $event_target_token,
                'created_by' => $event_created_by,
                'groups'     => $event_groups,
                'recipients' => array(
                    'providers'    => array_values( array_unique( array_map( 'sanitize_text_field', $providers_display ) ) ),
                    'coordinators' => array_values( array_unique( array_map( 'sanitize_text_field', $coordinators_display ) ) ),
                ),
            );
        }

        foreach ( $calendar_events_map as $date => $data ) {
            $providers_titles = array();
            $coordinator_titles = array();

            if ( ! empty( $data['titles']['providers'] ) && is_array( $data['titles']['providers'] ) ) {
                $providers_titles = array_values( array_unique( array_map( 'sanitize_text_field', $data['titles']['providers'] ) ) );
            }
            if ( ! empty( $data['titles']['coordinators'] ) && is_array( $data['titles']['coordinators'] ) ) {
                $coordinator_titles = array_values( array_unique( array_map( 'sanitize_text_field', $data['titles']['coordinators'] ) ) );
            }

            $groups = array();
            if ( ! empty( $providers_titles ) ) {
                $groups[] = 'providers';
            }
            if ( ! empty( $coordinator_titles ) ) {
                $groups[] = 'coordinators';
            }

            if ( empty( $groups ) ) {
                continue;
            }

            $events_payload = array();
            if ( ! empty( $data['events'] ) && is_array( $data['events'] ) ) {
                foreach ( $data['events'] as $entry ) {
                    if ( ! is_array( $entry ) ) {
                        continue;
                    }
                    $entry_title = isset( $entry['title'] ) ? sanitize_text_field( $entry['title'] ) : '';
                    $entry_groups = isset( $entry['groups'] ) && is_array( $entry['groups'] )
                        ? array_values( array_filter( array_map( 'sanitize_key', $entry['groups'] ), function( $group_key ) {
                            return in_array( $group_key, array( 'providers', 'coordinators' ), true );
                        } ) )
                        : array();
                    if ( empty( $entry_groups ) ) {
                        $entry_groups = $groups;
                    }
                    $entry_recipients = array(
                        'providers'    => array(),
                        'coordinators' => array(),
                    );
                    if ( isset( $entry['recipients'] ) && is_array( $entry['recipients'] ) ) {
                        if ( ! empty( $entry['recipients']['providers'] ) && is_array( $entry['recipients']['providers'] ) ) {
                            $entry_recipients['providers'] = array_values( array_filter( array_map( 'sanitize_text_field', $entry['recipients']['providers'] ) ) );
                        }
                        if ( ! empty( $entry['recipients']['coordinators'] ) && is_array( $entry['recipients']['coordinators'] ) ) {
                            $entry_recipients['coordinators'] = array_values( array_filter( array_map( 'sanitize_text_field', $entry['recipients']['coordinators'] ) ) );
                        }
                    }

                    $events_payload[] = array(
                        'id'          => isset( $entry['id'] ) ? sanitize_text_field( (string) $entry['id'] ) : '',
                        'title'       => $entry_title,
                        'editable'    => ! empty( $entry['editable'] ),
                        'target_token'=> isset( $entry['target_token'] ) ? sanitize_text_field( (string) $entry['target_token'] ) : '',
                        'created_by'  => isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0,
                        'groups'      => $entry_groups,
                        'recipients'  => $entry_recipients,
                    );
                }
            }

            $calendar_events[] = array(
                'date'   => $date,
                'groups' => $groups,
                'titles' => array(
                    'providers'    => $providers_titles,
                    'coordinators' => $coordinator_titles,
                ),
                'events' => $events_payload,
            );
        }

        if ( ! empty( $calendar_events ) ) {
            usort( $calendar_events, function( $a, $b ) {
                return strcmp( $a['date'], $b['date'] );
            } );
        }
    }

    $coord_calendar_attr = esc_attr( wp_json_encode( $calendar_events, JSON_UNESCAPED_UNICODE ) );
    $coord_editable_events = array();
    if ( $has_portal_access && function_exists( 'apf_scheduler_get_events' ) ) {
        $scheduler_events = apf_scheduler_get_events();
        if ( is_array( $scheduler_events ) ) {
            foreach ( $scheduler_events as $evt ) {
                if ( ! is_array( $evt ) ) {
                    continue;
                }
                $evt_id = isset( $evt['id'] ) ? sanitize_text_field( (string) $evt['id'] ) : '';
                if ( '' === $evt_id || strpos( $evt_id, 'coord_msg_' ) !== 0 ) {
                    continue;
                }
                $evt_created_by = isset( $evt['created_by'] ) ? (int) $evt['created_by'] : 0;
                if ( $evt_created_by !== $user_id ) {
                    continue;
                }
                $evt_date = isset( $evt['date'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $evt['date'] ) : '';
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $evt_date ) ) {
                    continue;
                }
                $evt_title = isset( $evt['title'] ) ? sanitize_text_field( $evt['title'] ) : '';
                $evt_rec   = isset( $evt['recipients'] ) && is_array( $evt['recipients'] ) ? $evt['recipients'] : array();
                $target_token = '';
                foreach ( $evt_rec as $recipient ) {
                    if ( ! is_array( $recipient ) ) {
                        continue;
                    }
                    $group = isset( $recipient['group'] ) ? sanitize_key( $recipient['group'] ) : 'providers';
                    if ( 'providers' !== $group ) {
                        continue;
                    }
                    $email   = isset( $recipient['email'] ) ? sanitize_email( $recipient['email'] ) : '';
                    $course  = isset( $recipient['course'] ) ? sanitize_text_field( $recipient['course'] ) : $current_course;
                    $dir_key = isset( $recipient['director_key'] ) ? sanitize_text_field( $recipient['director_key'] ) : $coordinator_key;
                    $candidate_token = $build_collab_token( $email, $course, $dir_key );
                    if ( '' === $target_token && '' !== $candidate_token ) {
                        $target_token = $candidate_token;
                    }
                }
                if ( '' === $target_token ) {
                    continue;
                }
                $coord_editable_events[] = array(
                    'id'     => $evt_id,
                    'date'   => $evt_date,
                    'title'  => $evt_title,
                    'token'  => $target_token,
                );
            }
        }
    }
    $coord_editable_events_attr = esc_attr( wp_json_encode( $coord_editable_events, JSON_UNESCAPED_UNICODE ) );

    $coordinator_requests       = array();
    $coordinator_pending_count  = 0;
    if ( $has_portal_access && function_exists( 'apf_get_coordinator_requests' ) ) {
        $all_requests = apf_get_coordinator_requests();
        if ( is_array( $all_requests ) ) {
            foreach ( $all_requests as $entry ) {
                if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
                    continue;
                }
                $entry_key   = isset( $entry['coordinator_key'] ) ? (string) $entry['coordinator_key'] : '';
                $entry_id    = isset( $entry['coordinator_id'] ) ? (string) $entry['coordinator_id'] : '';
                $entry_user  = isset( $entry['coordinator_user_id'] ) ? (int) $entry['coordinator_user_id'] : 0;
                $entry_email = isset( $entry['coordinator_email'] ) ? strtolower( sanitize_email( $entry['coordinator_email'] ) ) : '';
                $match       = false;

                if ( $coordinator_key && $entry_key && $coordinator_key === $entry_key ) {
                    $match = true;
                } elseif ( $entry_id && isset( $existing_entry['id'] ) && $entry_id === (string) $existing_entry['id'] ) {
                    $match = true;
                } elseif ( $entry_user > 0 && $entry_user === $user_id ) {
                    $match = true;
                } elseif ( $entry_email && $current_email && $entry_email === strtolower( $current_email ) ) {
                    $match = true;
                }

                if ( ! $match ) {
                    continue;
                }

                if ( isset( $entry['status'] ) && 'pending' === $entry['status'] ) {
                    $coordinator_pending_count++;
                }
                $coordinator_requests[] = $entry;
            }
        }
    }

    if ( ! empty( $coordinator_requests ) ) {
        usort( $coordinator_requests, function( $a, $b ){
            $a_ts = isset( $a['created_at'] ) ? (int) $a['created_at'] : 0;
            $b_ts = isset( $b['created_at'] ) ? (int) $b['created_at'] : 0;
            if ( $a_ts === $b_ts ) {
                return 0;
            }
            return ( $a_ts > $b_ts ) ? -1 : 1;
        } );
    }

    $coordinator_request_groups = array();
    if ( ! empty( $coordinator_requests ) ) {
        foreach ( $coordinator_requests as $request ) {
            $group_id = isset( $request['batch_id'] ) ? sanitize_text_field( (string) $request['batch_id'] ) : '';
            if ( '' === $group_id ) {
                $group_id = isset( $request['id'] ) ? sanitize_text_field( (string) $request['id'] ) : uniqid( 'req_' );
            }
            if ( ! isset( $coordinator_request_groups[ $group_id ] ) ) {
                $title  = isset( $request['note_title'] ) ? sanitize_text_field( $request['note_title'] ) : '';
                $body   = isset( $request['note_body'] ) ? sanitize_textarea_field( $request['note_body'] ) : '';
                $coordinator_request_groups[ $group_id ] = array(
                    'id'         => $group_id,
                    'title'      => $title,
                    'message'    => $body,
                    'created_at' => isset( $request['created_at'] ) ? (int) $request['created_at'] : 0,
                    'items'      => array(),
                    'pending'    => 0,
                    'approved'   => 0,
                    'rejected'   => 0,
                    'batch_submitted'    => ! empty( $request['batch_submitted'] ),
                    'batch_submitted_at' => isset( $request['batch_submitted_at'] ) ? (int) $request['batch_submitted_at'] : 0,
                );
            }

            $status = isset( $request['status'] ) ? $request['status'] : 'pending';
            if ( isset( $coordinator_request_groups[ $group_id ][ $status ] ) ) {
                $coordinator_request_groups[ $group_id ][ $status ]++;
            }

            $coordinator_request_groups[ $group_id ]['items'][] = array(
                'entry'   => $request,
                'details' => apf_coord_build_request_details( $request ),
            );
            if ( ! empty( $request['batch_submitted'] ) ) {
                $coordinator_request_groups[ $group_id ]['batch_submitted'] = true;
            }
            if ( isset( $request['batch_submitted_at'] ) ) {
                $previous = isset( $coordinator_request_groups[ $group_id ]['batch_submitted_at'] )
                    ? (int) $coordinator_request_groups[ $group_id ]['batch_submitted_at']
                    : 0;
                $coordinator_request_groups[ $group_id ]['batch_submitted_at'] = max( $previous, (int) $request['batch_submitted_at'] );
            }
        }

        $coordinator_request_groups = array_values( $coordinator_request_groups );
        usort( $coordinator_request_groups, function( $a, $b ){
            $a_ts = isset( $a['created_at'] ) ? (int) $a['created_at'] : 0;
            $b_ts = isset( $b['created_at'] ) ? (int) $b['created_at'] : 0;
            if ( $a_ts === $b_ts ) {
                return 0;
            }
            return ( $a_ts > $b_ts ) ? -1 : 1;
        } );
        foreach ( $coordinator_request_groups as $idx => $group_info ) {
            $pending_count = isset( $group_info['pending'] ) ? (int) $group_info['pending'] : 0;
            $items_total   = isset( $group_info['items'] ) ? count( $group_info['items'] ) : 0;
            $coordinator_request_groups[ $idx ]['can_submit'] = ( 0 === $pending_count && $items_total > 0 && empty( $group_info['batch_submitted'] ) );
        }
        unset( $group_info );
    }
    $coord_alert_default_date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : date_i18n( 'Y-m-d' );
    $coord_collaborators      = array();
    $coord_collaborators_index = array();
    if ( $has_portal_access && ! empty( $coordinator_requests ) ) {
        $seen_emails = array();
        foreach ( $coordinator_requests as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $email = isset( $entry['provider_email'] ) ? sanitize_email( $entry['provider_email'] ) : '';
            if ( '' === $email ) {
                continue;
            }
            $email_lower = strtolower( $email );
            if ( isset( $seen_emails[ $email_lower ] ) ) {
                continue;
            }

            $name   = isset( $entry['provider_name'] ) ? sanitize_text_field( $entry['provider_name'] ) : '';
            $course = isset( $entry['course'] ) ? sanitize_text_field( $entry['course'] ) : $current_course;
            $dir_key = isset( $entry['coordinator_key'] ) ? sanitize_text_field( $entry['coordinator_key'] ) : $coordinator_key;
            $token        = $build_collab_token( $email, $course, $dir_key );

            $collab_payload = array(
                'token'         => $token,
                'name'          => $name ?: $email,
                'email'         => $email,
                'course'        => $course,
                'director_key'  => $dir_key ?: $coordinator_key,
                'director_name' => isset( $entry['coordinator_name'] ) ? sanitize_text_field( $entry['coordinator_name'] ) : $current_name,
                'user_id'       => isset( $entry['provider_user_id'] ) ? (int) $entry['provider_user_id'] : 0,
            );

            $coord_collaborators[]          = $collab_payload;
            $coord_collaborators_index[ $token ] = $collab_payload;
            $seen_emails[ $email_lower ]    = true;
        }
        if ( ! empty( $coord_collaborators ) ) {
            usort( $coord_collaborators, function( $a, $b ) {
                return strcasecmp( $a['name'] ?? '', $b['name'] ?? '' );
            } );
        }
    }

    $visible_request_groups   = $coordinator_request_groups;
    $archived_request_groups  = array();
    $max_visible_request_cards = count( $coordinator_request_groups );
    $coord_request_months = array();
    foreach ( $coordinator_request_groups as $bundle ) {
        if ( empty( $bundle['created_at'] ) ) {
            continue;
        }
        $month_key = date_i18n( 'Y-m', (int) $bundle['created_at'] );
        $coord_request_months[ $month_key ] = date_i18n( 'm/Y', (int) $bundle['created_at'] );
    }
    if ( ! empty( $coord_request_months ) ) {
        krsort( $coord_request_months );
    }
    $has_archived_requests = ! empty( $archived_request_groups );
    $archive_icon_url = plugins_url( 'imgs/box-archive-svgrepo-com.svg', dirname( __DIR__ ) . '/fomulario_pagamento_faepa.php' );

    if ( isset( $_POST['apf_coord_alert_submit'] ) ) {
        if ( ! isset( $_POST['apf_coord_alert_nonce'] ) || ! wp_verify_nonce( $_POST['apf_coord_alert_nonce'], 'apf_coord_alert' ) ) {
            $alert_notice      = 'Não foi possível validar sua solicitação. Recarregue a página e tente novamente.';
            $alert_notice_type = 'error';
        } elseif ( ! $has_portal_access ) {
            $alert_notice      = 'Aguarde a aprovação do financeiro para enviar avisos.';
            $alert_notice_type = 'error';
        } elseif ( empty( $coord_collaborators_index ) ) {
            $alert_notice      = 'Nenhum colaborador disponível para receber avisos.';
            $alert_notice_type = 'error';
        } elseif ( ! function_exists( 'apf_scheduler_get_events' ) || ! function_exists( 'apf_scheduler_store_events' ) || ! function_exists( 'apf_scheduler_make_recipient_key' ) ) {
            $alert_notice      = 'Módulo de avisos indisponível no momento.';
            $alert_notice_type = 'error';
        } else {
            $action = isset( $_POST['apf_coord_alert_action'] ) ? sanitize_key( wp_unslash( $_POST['apf_coord_alert_action'] ) ) : 'add';
            $event_id = isset( $_POST['apf_coord_alert_event'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_coord_alert_event'] ) ) : '';
            $title = isset( $_POST['apf_coord_alert_title'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_coord_alert_title'] ) ) : '';
            if ( function_exists( 'mb_substr' ) ) {
                $title = trim( mb_substr( $title, 0, 120 ) );
            } else {
                $title = trim( substr( $title, 0, 120 ) );
            }
            $date = isset( $_POST['apf_coord_alert_date'] )
                ? preg_replace( '/[^0-9\-]/', '', (string) wp_unslash( $_POST['apf_coord_alert_date'] ) )
                : '';
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                $date = $coord_alert_default_date;
            }
            $tokens = array();
            if ( isset( $_POST['apf_coord_alert_targets'] ) ) {
                $raw_tokens = wp_unslash( $_POST['apf_coord_alert_targets'] );
                if ( is_array( $raw_tokens ) ) {
                    foreach ( $raw_tokens as $raw_token ) {
                        $token = sanitize_text_field( (string) $raw_token );
                        if ( '' !== $token ) {
                            $tokens[] = $token;
                        }
                    }
                }
            }
            $tokens = array_values( array_unique( $tokens ) );

            $selected = array();
            foreach ( $tokens as $token ) {
                if ( isset( $coord_collaborators_index[ $token ] ) ) {
                    $selected[] = $coord_collaborators_index[ $token ];
                }
            }

            $scheduler_events = apf_scheduler_get_events();
            $event_index      = null;
            if ( '' !== $event_id && is_array( $scheduler_events ) ) {
                foreach ( $scheduler_events as $idx => $evt ) {
                    $evt_id = isset( $evt['id'] ) ? sanitize_text_field( (string) $evt['id'] ) : '';
                    if ( $evt_id === $event_id ) {
                        $event_index = $idx;
                        break;
                    }
                }
            }

            $editable_target = false;
            if ( null !== $event_index && isset( $scheduler_events[ $event_index ] ) ) {
                $evt = $scheduler_events[ $event_index ];
                $editable_target = (
                    is_array( $evt )
                    && isset( $evt['created_by'] )
                    && (int) $evt['created_by'] === $user_id
                    && isset( $evt['id'] )
                    && strpos( (string) $evt['id'], 'coord_msg_' ) === 0
                );
            }

            if ( 'delete' === $action ) {
                if ( ! $editable_target || null === $event_index ) {
                    $alert_notice      = 'Você só pode excluir avisos criados por você.';
                    $alert_notice_type = 'error';
                } else {
                    unset( $scheduler_events[ $event_index ] );
                    $scheduler_events = array_values( $scheduler_events );
                    apf_scheduler_store_events( $scheduler_events );
                    $alert_notice      = 'Aviso excluído.';
                    $alert_notice_type = 'success';
                }
            } else {
                if ( '' === $title ) {
                    $alert_notice      = 'Descreva rapidamente o assunto do aviso.';
                    $alert_notice_type = 'error';
                } elseif ( empty( $selected ) ) {
                    $alert_notice      = 'Selecione ao menos um colaborador.';
                    $alert_notice_type = 'error';
                } else {
                    $recipient_payload = array();
                    $recipient_keys    = array();

                    foreach ( $selected as $collab ) {
                        $recipient_key = apf_scheduler_make_recipient_key(
                            isset( $collab['user_id'] ) ? (int) $collab['user_id'] : 0,
                            $collab['email'],
                            'providers'
                        );
                        if ( '' === $recipient_key ) {
                            $recipient_key = 'email_' . strtolower( $collab['email'] ) . '_providers';
                        }
                        if ( isset( $recipient_keys[ $recipient_key ] ) ) {
                            continue;
                        }
                        $recipient_keys[ $recipient_key ] = true;
                        $recipient_payload[] = array(
                            'key'           => $recipient_key,
                            'user_id'       => isset( $collab['user_id'] ) ? (int) $collab['user_id'] : 0,
                            'name'          => $collab['name'] ?: $collab['email'],
                            'email'         => $collab['email'],
                            'group'         => 'providers',
                            'director_key'  => $collab['director_key'] ?: $coordinator_key,
                            'director_name' => $collab['director_name'] ?: $current_name,
                            'course'        => $collab['course'] ?: $current_course,
                        );
                    }

                    if ( empty( $recipient_payload ) ) {
                        $alert_notice      = 'Não foi possível montar os destinatários do aviso.';
                        $alert_notice_type = 'error';
                    } else {
                        if ( 'update' === $action ) {
                            if ( ! $editable_target || null === $event_index ) {
                                $alert_notice      = 'Você só pode editar avisos criados por você.';
                                $alert_notice_type = 'error';
                            } else {
                                $scheduler_events[ $event_index ]['date']       = $date;
                                $scheduler_events[ $event_index ]['title']      = $title;
                                $scheduler_events[ $event_index ]['recipients'] = $recipient_payload;
                                $scheduler_events[ $event_index ]['updated_at'] = time();
                                $scheduler_events[ $event_index ]['updated_by'] = $user_id;
                                apf_scheduler_store_events( $scheduler_events );
                                $alert_notice      = 'Aviso atualizado.';
                                $alert_notice_type = 'success';
                            }
                        } else {
                            $scheduler_events[] = array(
                                'id'         => 'coord_msg_' . uniqid(),
                                'date'       => $date,
                                'title'      => $title,
                                'recipients' => $recipient_payload,
                                'created_by' => $user_id,
                                'created_at' => time(),
                            );
                            apf_scheduler_store_events( $scheduler_events );
                            $alert_notice      = 'Aviso enviado aos colaboradores selecionados.';
                            $alert_notice_type = 'success';
                        }
                    }
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
        $target = remove_query_arg( array( 'apf_coord_alert_notice', 'apf_coord_alert_status' ), $target );
        $target = add_query_arg( array(
            'apf_coord_alert_notice' => $alert_notice,
            'apf_coord_alert_status' => ( 'success' === $alert_notice_type ) ? 'success' : 'error',
        ), $target );
        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    $status_message = '';
    $status_class   = 'info';
    $blocked_message = '';
    switch ( $current_status ) {
        case 'approved':
            $status_class   = 'approved';
            $status_message = 'Vínculo aprovado. A agenda e os avisos já estão liberados abaixo.';
            break;
        case 'pending':
            $status_class   = 'pending';
            $status_message = 'Solicitação enviada. Aguarde a aprovação do financeiro.';
            $blocked_message = 'Assim que o financeiro aprovar seu vínculo, a agenda ficará disponível automaticamente.';
            break;
        case 'rejected':
            $status_class   = 'rejected';
            $status_message = 'O financeiro recusou sua solicitação. Revise os dados e envie novamente.';
            $blocked_message = 'Envie uma nova solicitação corrigindo os dados acima para que o financeiro possa aprovar seu acesso.';
            break;
        default:
            $status_class   = 'info';
            $status_message = 'Informe seus dados para solicitar aprovação do financeiro.';
            $blocked_message = 'Preencha e envie o formulário acima para que o financeiro analise seu vínculo.';
            break;
    }

    ob_start();
    ?>
    <div class="apf-coord-portal" style="font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif">
      <div class="apf-coord-card">
        <h2>Portal do Coordenador</h2>
        <p>Informe seu curso para que o financeiro mantenha os formulários atualizados.</p>

        <?php if ( $notice ) : ?>
          <div class="apf-coord-notice apf-coord-notice--<?php echo esc_attr( $notice_type ); ?>">
            <?php echo esc_html( $notice ); ?>
          </div>
        <?php endif; ?>

        <?php if ( $status_message ) : ?>
          <div class="apf-coord-status apf-coord-status--<?php echo esc_attr( $status_class ); ?>">
            <?php echo esc_html( $status_message ); ?>
          </div>
        <?php endif; ?>

        <?php $show_form = ( 'pending' !== $current_status ); ?>

        <?php if ( $show_form ) : ?>
          <?php if ( $has_portal_access ) : ?>
            <div class="apf-coord-edit-bar">
              <button type="button" class="apf-coord-edit-btn" id="apfCoordEditToggle" aria-pressed="false">
                Editar dados
              </button>
              <span class="apf-coord-edit-hint">Clique para atualizar seu nome ou e-mail.</span>
            </div>
          <?php endif; ?>

          <form method="post" class="apf-coord-form" data-locked="<?php echo $has_portal_access ? '1' : '0'; ?>">
            <?php wp_nonce_field( 'apf_coord_portal', 'apf_coord_nonce' ); ?>
            <input type="hidden" name="apf_coord_submit" value="1">

            <label>
              <span>Nome completo</span>
              <input type="text" name="apf_coord_name" value="<?php echo esc_attr( $current_name ); ?>" required data-lockable="1" <?php echo $has_portal_access ? 'disabled' : ''; ?>>
            </label>

          <label>
            <span>E-mail</span>
            <input type="email" name="apf_coord_email" value="<?php echo esc_attr( $current_email ); ?>" required data-lockable="1" <?php echo $has_portal_access ? 'disabled' : ''; ?>>
          </label>

          <?php if ( $has_portal_access ) : ?>
            <input type="hidden" name="apf_coord_course" value="<?php echo esc_attr( $current_course ); ?>">
          <?php else : ?>
            <label>
              <span>Curso</span>
              <?php if ( ! empty( $available_courses ) ) : ?>
                <select name="apf_coord_course" id="apfCoordCourseSelect" required>
                  <option value="" disabled <?php selected( $current_course, '' ); ?>>Selecione um curso</option>
                  <?php foreach ( $available_courses as $course_label ) : ?>
                    <option value="<?php echo esc_attr( $course_label ); ?>" <?php selected( $current_course, $course_label ); ?>>
                      <?php echo esc_html( $course_label ); ?>
                    </option>
                  <?php endforeach; ?>
                  <option value="__manual__" <?php selected( true, $is_manual_course ); ?>>Outro curso...</option>
                </select>
                <input type="text"
                       name="apf_coord_course_manual"
                       id="apfCoordCourseManual"
                       placeholder="Digite o nome do curso"
                       value="<?php echo esc_attr( $is_manual_course ? $current_course : '' ); ?>"
                       style="margin-top:8px; <?php echo $is_manual_course ? '' : 'display:none;'; ?>">
              <?php else : ?>
                <input type="text" name="apf_coord_course" value="<?php echo esc_attr( $current_course ); ?>" required>
              <?php endif; ?>
            </label>
          <?php endif; ?>

            <button type="submit" class="apf-coord-btn" <?php echo $has_portal_access ? 'disabled' : ''; ?>>Salvar vínculo</button>
          </form>
        <?php endif; ?>

        <?php if ( $has_portal_access ) : ?>
          <div class="apf-coord-summary">
            <h3>Vínculo registrado</h3>
            <p><strong>Nome:</strong> <?php echo esc_html( $current_name ); ?></p>
            <p><strong>E-mail:</strong> <?php echo esc_html( $current_email ); ?></p>
            <p><strong>Curso:</strong> <?php echo esc_html( $current_course ); ?></p>
            <p class="apf-coord-summary__hint">Precisa alterar o curso aprovado? Solicite ao financeiro pelo canal oficial.</p>
          </div>

          <section class="apf-coord-requests" aria-labelledby="apfCoordRequestsTitle">
            <div class="apf-coord-requests__head">
              <div>
                <h3 id="apfCoordRequestsTitle">Solicitações de colaboradores</h3>
                <p>Acompanhe os envios enviados pelo financeiro e responda cada um deles.</p>
              </div>
              <?php if ( $has_archived_requests || $coordinator_pending_count > 0 ) : ?>
                <div class="apf-coord-requests__tools">
                  <?php if ( $has_archived_requests ) : ?>
                    <button type="button"
                      class="apf-coord-archive-btn"
                      id="apfCoordArchiveToggle"
                      aria-haspopup="dialog"
                      aria-expanded="false"
                      aria-label="Ver solicitações antigas">
                      <img src="<?php echo esc_url( $archive_icon_url ); ?>" alt="" role="presentation">
                    </button>
                  <?php endif; ?>
                  <?php if ( $coordinator_pending_count > 0 ) : ?>
                    <span class="apf-coord-requests__badge"><?php echo esc_html( $coordinator_pending_count . ' pendente(s)' ); ?></span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

            <?php if ( $request_notice ) : ?>
              <div class="apf-coord-notice apf-coord-notice--<?php echo esc_attr( $request_notice_type ); ?>">
                <?php echo esc_html( $request_notice ); ?>
              </div>
            <?php endif; ?>

            <?php if ( ! empty( $visible_request_groups ) ) : ?>
              <div class="apf-coord-requests__controls">
                <label class="apf-coord-requests__filter">
                  <span>Filtrar por mês</span>
                  <select id="apfCoordReqMonth">
                    <option value=""><?php echo esc_html( 'Todos os meses' ); ?></option>
                    <?php foreach ( $coord_request_months as $month_key => $month_label ) : ?>
                      <option value="<?php echo esc_attr( $month_key ); ?>"><?php echo esc_html( $month_label ); ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <div class="apf-coord-requests__pager" aria-label="Paginação das solicitações">
                  <button type="button" class="apf-coord-btn apf-coord-btn--ghost apf-coord-requests__pager-btn" id="apfCoordReqPrev" aria-label="Página anterior">&larr;</button>
                  <span id="apfCoordReqLabel">1/1</span>
                  <button type="button" class="apf-coord-btn apf-coord-btn--ghost apf-coord-requests__pager-btn" id="apfCoordReqNext" aria-label="Próxima página">&rarr;</button>
                </div>
              </div>
              <div class="apf-coord-requests__cards">
                <?php foreach ( $visible_request_groups as $group ) :
                    $group_id     = $group['id'];
                    $group_title  = $group['title'] ?: 'Solicitação do financeiro';
                    $group_created= isset( $group['created_at'] ) ? (int) $group['created_at'] : 0;
                    $group_sent   = $group_created ? date_i18n( 'd/m/Y H:i', $group_created ) : '';
                    $group_month  = $group_created ? date_i18n( 'Y-m', $group_created ) : '';
                    $group_total  = count( $group['items'] );
                    $group_pending  = isset( $group['pending'] ) ? (int) $group['pending'] : 0;
                    $group_approved = isset( $group['approved'] ) ? (int) $group['approved'] : 0;
                    $group_rejected = isset( $group['rejected'] ) ? (int) $group['rejected'] : 0;
                    $group_submitted = ! empty( $group['batch_submitted'] );
                    $badge_state = 'pending';
                    if ( $group_submitted ) {
                        $badge_text  = 'Concluído';
                        $badge_state = 'done';
                    } elseif ( $group_pending >= $group_total ) {
                        $badge_text  = 'Pendente';
                        $badge_state = 'pending';
                    } elseif ( $group_pending > 0 ) {
                        $badge_text  = 'Em andamento';
                        $badge_state = 'progress';
                    } else {
                        $badge_text  = 'Em andamento';
                        $badge_state = 'progress';
                    }
                ?>
                  <article class="apf-coord-request-card" data-status="<?php echo esc_attr( $badge_state ); ?>" data-submitted="<?php echo $group_submitted ? '1' : '0'; ?>" data-coord-month="<?php echo esc_attr( $group_month ); ?>">
                    <header class="apf-coord-request-card__header">
                      <div>
                        <strong><?php echo esc_html( $group_title ); ?></strong>
                        <?php if ( $group_sent ) : ?>
                          <small>Enviado em <?php echo esc_html( $group_sent ); ?></small>
                        <?php endif; ?>
                      </div>
                      <span class="apf-coord-request-card__badge"><?php echo esc_html( $badge_text ); ?></span>
                    </header>
                    <div class="apf-coord-request-card__foot">
                      <span><?php echo esc_html( $group_total . ' colaborador(es)' ); ?></span>
                      <button type="button" class="apf-coord-btn apf-coord-btn--primary" data-request-modal="<?php echo esc_attr( $group_id ); ?>">
                        Ver detalhes
                      </button>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
              <p class="apf-coord-requests__empty" id="apfCoordReqEmpty" hidden>Nenhuma solicitação encontrada.</p>
            <?php else : ?>
              <p class="apf-coord-requests__empty">Nenhuma solicitação disponível para este curso.</p>
            <?php endif; ?>
          </section>

          <?php if ( $has_archived_requests ) : ?>
            <div class="apf-coord-archive-modal" id="apfCoordArchiveModal" aria-hidden="true">
              <div class="apf-coord-archive-modal__overlay" data-archive-close></div>
              <div class="apf-coord-archive-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfCoordArchiveTitle">
                <div class="apf-coord-archive-modal__head">
                  <div>
                    <h4 id="apfCoordArchiveTitle">Solicitações antigas</h4>
                    <p>Solicitações mais antigas ficam agrupadas nesta área.</p>
                  </div>
                  <button type="button" class="apf-coord-archive-modal__close" data-archive-close aria-label="Fechar">&times;</button>
                </div>
                <div class="apf-coord-archive-modal__body">
                  <?php foreach ( $archived_request_groups as $group ) :
                      $group_id    = $group['id'];
                      $group_title = $group['title'] ?: 'Solicitação do financeiro';
                      $group_sent  = ! empty( $group['created_at'] ) ? date_i18n( 'd/m/Y H:i', (int) $group['created_at'] ) : '';
                      $panel_id    = 'apfCoordArchivePanel-' . sanitize_html_class( $group_id );
                  ?>
                    <article class="apf-coord-archive-entry">
                      <button type="button"
                        class="apf-coord-archive-entry__toggle"
                        data-archive-toggle="<?php echo esc_attr( $group_id ); ?>"
                        aria-expanded="false"
                        aria-controls="<?php echo esc_attr( $panel_id ); ?>">
                        <span class="apf-coord-archive-entry__title"><?php echo esc_html( $group_title ); ?></span>
                        <span class="apf-coord-archive-entry__chevron" aria-hidden="true"></span>
                      </button>
                      <?php if ( $group_sent ) : ?>
                        <small class="apf-coord-archive-entry__date">Enviado em <?php echo esc_html( $group_sent ); ?></small>
                      <?php endif; ?>
                      <div class="apf-coord-archive-entry__panel" id="<?php echo esc_attr( $panel_id ); ?>" data-archive-panel="<?php echo esc_attr( $group_id ); ?>" hidden>
                        <?php echo apf_coord_render_request_detail_inner( $group, array(
                            'lock_actions' => ! empty( $group['batch_submitted'] ),
                        ) ); ?>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if ( ! empty( $coordinator_request_groups ) ) : ?>
            <div class="apf-coord-request-cache" hidden aria-hidden="true">
              <?php foreach ( $coordinator_request_groups as $group ) :
                  $group_id = $group['id'];
                  $group_pending = isset( $group['pending'] ) ? (int) $group['pending'] : 0;
                  $group_submitted = ! empty( $group['batch_submitted'] );
                  $group_submitted_at = ( ! empty( $group['batch_submitted_at'] ) )
                      ? date_i18n( 'd/m/Y H:i', (int) $group['batch_submitted_at'] )
                      : '';
              ?>
                <div class="apf-coord-request-detail" id="apfCoordRequestDetail-<?php echo esc_attr( $group_id ); ?>" hidden data-submitted="<?php echo $group_submitted ? '1' : '0'; ?>">
                  <?php echo apf_coord_render_request_detail_inner( $group, array(
                      'lock_actions' => $group_submitted,
                  ) ); ?>
                  <div class="apf-coord-request-detail__footer">
                    <?php if ( $group_submitted ) : ?>
                      <p class="apf-coord-request-detail__status apf-coord-request-detail__status--success">
                        Enviado ao financeiro<?php echo $group_submitted_at ? ' em ' . esc_html( $group_submitted_at ) : ''; ?>.
                      </p>
                    <?php else : ?>
                      <p class="apf-coord-request-detail__status apf-coord-request-detail__status--warning" data-pending-warning <?php echo ( $group_pending > 0 ) ? '' : 'hidden'; ?>>
                        Valide ou recuse todos os colaboradores para liberar o envio.
                      </p>
                      <form method="post" class="apf-coord-request__resend" data-resend-form <?php echo ( $group_pending > 0 ) ? 'hidden' : ''; ?>>
                        <?php wp_nonce_field( 'apf_coord_batch', 'apf_coord_batch_nonce' ); ?>
                        <input type="hidden" name="apf_coord_batch_id" value="<?php echo esc_attr( $group_id ); ?>">
                        <button type="submit" name="apf_coord_batch_submit" value="1" class="apf-coord-btn apf-coord-btn--primary">
                          Enviar ao financeiro
                        </button>
                        <p class="apf-coord-request-detail__status">
                          Após o envio, este lote ficará bloqueado para edição.
                        </p>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="apf-coord-request-modal" id="apfCoordRequestModal" aria-hidden="true">
            <div class="apf-coord-request-modal__overlay" data-req-close></div>
            <div class="apf-coord-request-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfCoordRequestModalTitle">
              <div class="apf-coord-request-modal__head">
                <h4 id="apfCoordRequestModalTitle">Detalhes da solicitação</h4>
                <button type="button" class="apf-coord-request-modal__close" data-req-close aria-label="Fechar">&times;</button>
              </div>
              <div class="apf-coord-request-modal__content" id="apfCoordRequestModalContent">
                <p class="apf-coord-request-modal__empty">Selecione uma solicitação para visualizar os colaboradores.</p>
              </div>
              <div class="apf-coord-reject" id="apfCoordRejectOverlay" aria-hidden="true">
                <div class="apf-coord-reject__box" role="dialog" aria-modal="true" aria-labelledby="apfCoordRejectTitle">
                  <div class="apf-coord-reject__header">
                    <h5 id="apfCoordRejectTitle">Informe o motivo da recusa</h5>
                    <p class="apf-coord-reject__subtitle">Explique rapidamente ao financeiro por que este envio será recusado.</p>
                  </div>
                  <textarea id="apfCoordRejectMessage" rows="4" placeholder="Descreva o motivo da recusa"></textarea>
                  <div class="apf-coord-reject__actions">
                    <button type="button" class="apf-coord-btn apf-coord-btn--ghost-danger" data-reject-cancel>Cancelar</button>
                    <button type="button" class="apf-coord-btn apf-coord-btn--danger" data-reject-confirm>Confirmar recusa</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="apf-coord-calendar" id="apfCoordCalendar" data-events="<?php echo $coord_calendar_attr; ?>" data-compose-events="<?php echo $coord_editable_events_attr; ?>" data-user-id="<?php echo esc_attr( $user_id ); ?>">
            <div class="apf-coord-calendar__filters" aria-label="Filtrar agenda">
              <button type="button" class="apf-coord-calendar__filter is-active" data-calendar-filter="coordinators">Minha agenda</button>
              <button type="button" class="apf-coord-calendar__filter" data-calendar-filter="providers">Agenda dos colaboradores</button>
            </div>
            <div class="apf-coord-calendar__wrap">
              <div class="apf-coord-calendar__body"></div>
              <div class="apf-coord-calendar__compose" id="apfCoordCompose" aria-hidden="true">
                <div class="apf-coord-calendar__compose-head">
                  <div>
                    <p class="apf-coord-calendar__eyebrow">Agenda dos colaboradores</p>
                    <h4>Enviar aviso</h4>
                    <p class="apf-coord-calendar__hint">Selecione um dia no calendário, escolha o destinatário e envie a mensagem.</p>
                  </div>
                  <span class="apf-coord-alerts__pill">Somente seu curso</span>
                </div>
                <?php if ( $alert_notice ) : ?>
                  <div class="apf-coord-notice apf-coord-notice--<?php echo esc_attr( $alert_notice_type ); ?>">
                    <?php echo esc_html( $alert_notice ); ?>
                  </div>
                <?php endif; ?>
                <form method="post" class="apf-coord-calendar__form" id="apfCoordAlertForm">
                  <?php wp_nonce_field( 'apf_coord_alert', 'apf_coord_alert_nonce' ); ?>
                  <input type="hidden" name="apf_coord_alert_submit" value="1">
                  <input type="hidden" name="apf_coord_alert_action" id="apfCoordComposeAction" value="add">
                  <input type="hidden" name="apf_coord_alert_event" id="apfCoordComposeEvent" value="">
                  <input type="hidden" name="apf_coord_alert_date" id="apfCoordComposeDate" value="<?php echo esc_attr( $coord_alert_default_date ); ?>">
                  <div class="apf-coord-calendar__form-grid">
                    <div class="apf-coord-calendar__field">
                      <span>Dia selecionado</span>
                      <strong id="apfCoordComposeDateLabel"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $coord_alert_default_date ) ) ); ?></strong>
                      <p class="apf-coord-calendar__form-hint">Clique em um dia na agenda dos colaboradores para alterar.</p>
                    </div>
                    <label class="apf-coord-calendar__field">
                      <span>Colaborador</span>
                      <select name="apf_coord_alert_targets[]" id="apfCoordComposeTarget" required>
                        <option value="">Selecione</option>
                        <?php foreach ( $coord_collaborators as $collab ) : ?>
                          <option value="<?php echo esc_attr( $collab['token'] ); ?>">
                            <?php echo esc_html( $collab['name'] ); ?> — <?php echo esc_html( $collab['email'] ); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <?php if ( empty( $coord_collaborators ) ) : ?>
                        <p class="apf-coord-calendar__form-hint">Nenhum colaborador disponível ainda.</p>
                      <?php endif; ?>
                    </label>
                    <label class="apf-coord-calendar__field apf-coord-calendar__field--wide">
                      <span>Mensagem</span>
                      <input type="text" name="apf_coord_alert_title" maxlength="120" placeholder="Ex.: Enviar nota fiscal até sexta-feira" required>
                    </label>
                  </div>
                  <div class="apf-coord-calendar__form-actions">
                    <button type="submit" class="apf-coord-btn" <?php echo empty( $coord_collaborators ) ? 'disabled' : ''; ?>>Enviar aviso</button>
                    <button type="button" class="apf-coord-btn apf-coord-btn--ghost-danger" id="apfCoordComposeDelete" hidden>Excluir aviso</button>
                    <p class="apf-coord-calendar__form-hint">O aviso aparecerá no dia selecionado para o colaborador escolhido.</p>
                  </div>
                </form>
              </div>
            </div>
            <div class="apf-coord-calendar__legend" aria-label="Legenda do calendário" id="apfCoordLegend">
              <span data-legend="providers-other"><span class="apf-coord-calendar__legend-dot apf-coord-calendar__legend-dot--providers" aria-hidden="true"></span> Avisos do financeiro</span>
              <span data-legend="providers-own"><span class="apf-coord-calendar__legend-dot apf-coord-calendar__legend-dot--providers-own" aria-hidden="true"></span> Avisos que eu enviei</span>
            </div>
          </div>
        <?php if ( empty( $calendar_events ) ) : ?>
          <p class="apf-coord-calendar__empty">Nenhum aviso programado até o momento.</p>
        <?php else : ?>
          <p class="apf-coord-calendar__hint">Alterne a agenda para ver avisos dos seus colaboradores ou apenas os enviados diretamente a você.</p>
        <?php endif; ?>
        <div class="apf-coord-modal" id="apfCoordModal" aria-hidden="true">
          <div class="apf-coord-modal__overlay" data-modal-close></div>
          <div class="apf-coord-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfCoordModalTitle">
            <div class="apf-coord-modal__head">
              <h4 id="apfCoordModalTitle">Avisos da data</h4>
              <button type="button" class="apf-coord-modal__close" data-modal-close aria-label="Fechar">&times;</button>
            </div>
          <div class="apf-coord-modal__content">
            <p class="apf-coord-modal__empty">Nenhum aviso programado para esta data.</p>
            <div class="apf-coord-modal__items"></div>
          </div>
        </div>
      </div>
      <div class="apf-coord-alert-edit" id="apfCoordAlertEdit" aria-hidden="true">
        <div class="apf-coord-alert-edit__overlay" data-alert-edit-close></div>
        <div class="apf-coord-alert-edit__dialog" role="dialog" aria-modal="true" aria-labelledby="apfCoordAlertEditTitle">
          <div class="apf-coord-alert-edit__head">
            <h5 id="apfCoordAlertEditTitle">Editar aviso</h5>
            <p>Altere o destinatário ou a mensagem e salve.</p>
          </div>
          <label class="apf-coord-alert-edit__field">
            <span>Colaborador</span>
            <select id="apfCoordAlertEditTarget" required>
              <option value="">Selecione</option>
            </select>
          </label>
          <label class="apf-coord-alert-edit__field">
            <span>Mensagem</span>
            <input type="text" id="apfCoordAlertEditMessage" maxlength="120" placeholder="Ex.: Enviar nota fiscal até sexta-feira" required>
          </label>
          <div class="apf-coord-alert-edit__actions">
            <button type="button" class="apf-coord-btn apf-coord-btn--ghost" data-alert-edit-cancel>Cancelar</button>
            <button type="button" class="apf-coord-btn" data-alert-edit-save>Salvar alterações</button>
          </div>
        </div>
      </div>
      <?php elseif ( $blocked_message ) : ?>
        <div class="apf-coord-blocked">
          <p><?php echo esc_html( $blocked_message ); ?></p>
        </div>
      <?php endif; ?>
      </div>
    </div>
    <style>
      .apf-coord-card{
        margin:24px auto;
        background:#fff;
        border-radius:16px;
        border:1px solid #e4e7ec;
        box-shadow:0 10px 30px rgba(15,23,42,.12);
        padding:24px 28px;
      }
      .apf-coord-card h2{
        margin:0 0 8px;
        font-size:22px;
      }
      .apf-coord-card p{
        margin:0 0 16px;
        color:#475467;
        font-size:14px;
      }
      .apf-coord-form{
        display:flex;
        flex-direction:column;
        gap:16px;
      }
      .apf-coord-form label{
        display:flex;
        flex-direction:column;
        gap:6px;
        font-size:13px;
        color:#344054;
      }
      .apf-coord-form input,
      .apf-coord-form select{
        border:1px solid #d0d5dd;
        border-radius:10px;
        padding:10px 12px;
        font-size:14px;
        background:#fff;
      }
      .apf-coord-btn{
        margin-top:8px;
        border:none;
        border-radius:12px;
        background:#1f6feb;
        color:#fff;
        font-weight:600;
        padding:12px 16px;
        cursor:pointer;
        transition:background .15s ease,color .15s ease,border-color .15s ease,transform .15s ease;
      }
      .apf-coord-btn:hover{
        background:#154cba;
      }
      .apf-coord-notice{
        border-radius:12px;
        padding:10px 12px;
        font-size:13px;
        margin-bottom:12px;
        transition:opacity .3s ease, transform .3s ease;
      }
      .apf-coord-notice--success{
        background:#ecfdf3;
        border:1px solid #bbf7d0;
        color:#166534;
      }
      .apf-coord-notice--error{
        background:#fef2f2;
        border:1px solid #fecaca;
        color:#991b1b;
      }
      .apf-coord-status{
        border-radius:12px;
        padding:10px 12px;
        font-size:13px;
        margin-bottom:16px;
        border:1px solid #c7d2fe;
        background:#eef2ff;
        color:#312e81;
        transition:opacity .3s ease, transform .3s ease;
      }
      .apf-coord-status--approved{
        background:#ecfdf3;
        border-color:#bbf7d0;
        color:#166534;
      }
      .apf-coord-status--pending{
        background:#fefce8;
        border-color:#fde68a;
        color:#92400e;
      }
      .apf-coord-status--rejected{
        background:#fef3f2;
        border-color:#fecdd3;
        color:#b42318;
      }
      .apf-coord-status--info{
        background:#eef2ff;
        border-color:#c7d2fe;
        color:#1d4ed8;
      }
      .apf-coord-edit-bar{
        display:flex;
        align-items:center;
        gap:12px;
        margin:0 0 16px;
      }
      .apf-coord-edit-btn{
        border:1px solid #c7d2fe;
        background:#eef2ff;
        color:#1d4ed8;
        border-radius:999px;
        padding:8px 16px;
        font-size:13px;
        font-weight:600;
        cursor:pointer;
      }
      .apf-coord-edit-btn:hover,
      .apf-coord-edit-btn:focus{
        background:#dbe4ff;
        border-color:#a5b4fc;
        outline:none;
      }
      .apf-coord-edit-hint{
        font-size:12px;
        color:#475467;
      }
      .apf-coord-blocked{
        margin-top:16px;
        border-radius:12px;
        border:1px dashed #c7d2fe;
        background:#f8f9ff;
        padding:12px;
        font-size:13px;
        color:#475467;
      }
      .apf-coord-summary{
        border:1px solid #e4e7ec;
        border-radius:12px;
        background:#f8fafc;
        padding:16px 18px;
        margin:12px 0;
      }
      .apf-coord-summary h3{
        margin:0 0 10px;
        font-size:16px;
        color:#0f172a;
      }
      .apf-coord-summary p{
        margin:4px 0;
        color:#475467;
        font-size:14px;
      }
      .apf-coord-summary__hint{
        margin-top:10px;
        font-size:12px;
        color:#b45309;
      }
      .apf-coord-requests{
        border:1px solid #e4e7ec;
        border-radius:16px;
        background:#f8fafc;
        padding:18px 20px;
        margin:20px 0;
        display:flex;
        flex-direction:column;
        gap:16px;
      }
      .apf-coord-requests__head{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
      }
      .apf-coord-requests__tools{
        display:flex;
        align-items:center;
        gap:10px;
      }
      .apf-coord-requests__head h3{
        margin:0;
        font-size:17px;
        color:#0f172a;
      }
      .apf-coord-requests__head p{
        margin:4px 0 0;
        font-size:13px;
        color:#475467;
      }
      .apf-coord-archive-btn{
        width:42px;
        height:42px;
        border-radius:12px;
        border:1px solid #d0d5dd;
        background:#fff;
        display:flex;
        align-items:center;
        justify-content:center;
        padding:0;
        cursor:pointer;
        transition:background .2s ease,border-color .2s ease,box-shadow .2s ease;
      }
      .apf-coord-archive-btn img{
        width:18px;
        height:18px;
        pointer-events:none;
      }
      .apf-coord-archive-btn:hover,
      .apf-coord-archive-btn:focus-visible{
        background:#eef2ff;
        border-color:#c7d2fe;
        box-shadow:0 0 0 2px rgba(99,102,241,.25);
        outline:none;
      }
      .apf-coord-requests__badge{
        border-radius:999px;
        background:#fef3c7;
        color:#92400e;
        font-size:12px;
        font-weight:600;
        padding:6px 12px;
        white-space:nowrap;
      }
      .apf-coord-requests__cards{
        display:grid;
        grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
        gap:10px;
      }
      .apf-coord-request-card{
        border:1px solid #d0d5dd;
        border-radius:16px;
        background:#fff;
        padding:12px;
        display:flex;
        flex-direction:column;
        gap:10px;
        min-height:0;
        max-width:100%;
        box-sizing:border-box;
      }
      .apf-coord-request-card[data-status="pending"]{
        border-color:#fde68a;
        background:#fffbeb;
      }
      .apf-coord-request-card[data-status="progress"]{
        border-color:#bfdbfe;
        background:#eff6ff;
      }
      .apf-coord-request-card[data-status="done"]{
        border-color:#bbf7d0;
        background:#f0fdf4;
      }
      .apf-coord-request-card__header{
        display:flex;
        justify-content:space-between;
        gap:12px;
        align-items:flex-start;
      }
      .apf-coord-request-card__header strong{
        display:block;
        font-size:15px;
        color:#0f172a;
      }
      .apf-coord-request-card__header small{
        display:block;
        font-size:12px;
        color:#475467;
      }
      .apf-coord-request-card__badge{
        border-radius:999px;
        padding:6px 12px;
        font-size:12px;
        font-weight:600;
        background:#e0e7ff;
        color:#3730a3;
        white-space:nowrap;
      }
      .apf-coord-request-card__excerpt{
        margin:0;
        font-size:13px;
        color:#475467;
        line-height:1.4;
      }
      .apf-coord-request-card__people{
        list-style:none;
        padding:0;
        margin:0;
        display:flex;
        flex-wrap:wrap;
        gap:6px;
      }
      .apf-coord-request-card__people li{
        background:#eef2ff;
        color:#4338ca;
        padding:4px 10px;
        border-radius:999px;
        font-size:12px;
      }
      .apf-coord-request-card__foot{
        display:flex;
        align-items:center;
        justify-content:space-between;
        font-size:13px;
        color:#475467;
        margin-top:auto;
      }
      .apf-coord-requests__controls{
        display:flex;
        justify-content:space-between;
        align-items:flex-end;
        gap:12px;
        flex-wrap:wrap;
        margin:0 0 10px;
      }
      .apf-coord-requests__filter{
        display:flex;
        flex-direction:column;
        gap:6px;
        min-width:200px;
        font-size:12px;
        color:#475467;
        margin-right:auto;
      }
      .apf-coord-requests__filter select{
        height:36px;
        border:1px solid #d0d5dd;
        border-radius:10px;
        padding:0 10px;
      }
      .apf-coord-requests__filter select:focus{
        border-color:#2563eb;
        outline:none;
        box-shadow:0 0 0 3px rgba(37,99,235,.18);
      }
      .apf-coord-requests__pager{
        display:flex;
        align-items:baseline;
        gap:8px;
        font-size:13px;
        color:#475467;
        justify-content:center;
      }
      .apf-coord-requests__pager span{
        min-width:48px;
        text-align:center;
      }
      .apf-coord-requests__pager-btn{
        padding:6px 10px;
        min-width:36px;
        color:var(--apf-primary);
        border-color:currentColor;
      }
      .apf-coord-requests__hidden{ display:none !important; }
      .apf-coord-requests__controls{
        display:flex;
        justify-content:flex-end;
        margin:0 0 10px;
      }
      .apf-coord-requests__pager{
        display:flex;
        align-items:baseline;
        justify-content:center;
        gap:8px;
        font-size:13px;
        color:#475467;
      }
      .apf-coord-requests__pager-btn{
        padding:6px 10px;
        min-width:36px;
        color:var(--apf-primary);
        border-color:currentColor;
      }
      .apf-coord-requests__hidden{ display:none !important; }
      .apf-coord-requests__empty{
        margin:0;
        font-size:13px;
        color:#475467;
      }
      .apf-coord-archive-modal{
        position:fixed;
        inset:0;
        z-index:1100;
        display:flex;
        align-items:center;
        justify-content:center;
        background:rgba(15,23,42,.62);
        opacity:0;
        visibility:hidden;
        transition:opacity .25s ease;
        pointer-events:none;
      }
      .apf-coord-archive-modal[aria-hidden="false"]{
        opacity:1;
        visibility:visible;
        pointer-events:auto;
      }
      .apf-coord-archive-modal__overlay{
        position:absolute;
        inset:0;
      }
      .apf-coord-archive-modal__dialog{
        position:relative;
        width:min(720px, calc(100vw - 32px));
        max-height:90vh;
        background:#fff;
        border-radius:20px;
        border:1px solid #e4e7ec;
        box-shadow:0 28px 60px rgba(15,23,42,.4);
        display:flex;
        flex-direction:column;
        overflow:hidden;
      }
      .apf-coord-archive-modal__head{
        padding:18px 22px;
        border-bottom:1px solid #e4e7ec;
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
      }
      .apf-coord-archive-modal__head h4{
        margin:0;
        font-size:18px;
        color:#0f172a;
      }
      .apf-coord-archive-modal__head p{
        margin:6px 0 0;
        font-size:13px;
        color:#475467;
      }
      .apf-coord-archive-modal__close{
        border:none;
        background:transparent;
        color:#94a3b8;
        font-size:28px;
        cursor:pointer;
      }
      .apf-coord-archive-modal__body{
        padding:20px 22px;
        overflow:auto;
        display:flex;
        flex-direction:column;
        gap:16px;
      }
      .apf-coord-archive-entry{
        border:1px solid #e4e7ec;
        border-radius:16px;
        padding:14px 16px;
        background:#f8fafc;
        display:flex;
        flex-direction:column;
        gap:8px;
      }
      .apf-coord-archive-entry__toggle{
        border:none;
        background:transparent;
        padding:0;
        display:flex;
        justify-content:space-between;
        align-items:center;
        cursor:pointer;
      }
      .apf-coord-archive-entry__title{
        font-size:15px;
        font-weight:600;
        color:#0f172a;
      }
      .apf-coord-archive-entry__chevron{
        width:16px;
        height:16px;
        border-right:2px solid #475467;
        border-bottom:2px solid #475467;
        transform:rotate(45deg);
        transition:transform .2s ease;
      }
      .apf-coord-archive-entry__toggle[aria-expanded="true"] .apf-coord-archive-entry__chevron{
        transform:rotate(-135deg);
      }
      .apf-coord-archive-entry__date{
        font-size:12px;
        color:#475467;
      }
      .apf-coord-archive-entry__panel{
        border:1px solid #e4e7ec;
        border-radius:14px;
        background:#fff;
        padding:12px;
      }
      .apf-coord-archive-entry__panel[hidden]{
        display:none;
      }
      .apf-coord-archive-entry__panel .apf-coord-request-detail__head{
        display:none;
      }
      .apf-coord-archive-entry__panel .apf-coord-request-detail__message{
        margin-top:0;
      }
      .apf-coord-request-detail{
        display:block;
      }
      .apf-coord-request-detail[hidden]{
        display:none;
      }
      .apf-coord-request-modal{
        position:fixed;
        inset:0;
        z-index:1200;
        display:flex;
        align-items:center;
        justify-content:center;
        background:rgba(15,23,42,.65);
        opacity:0;
        visibility:hidden;
        transition:opacity .25s ease;
      }
      .apf-coord-request-modal[aria-hidden="false"]{
        opacity:1;
        visibility:visible;
      }
      .apf-coord-request-modal__overlay{
        position:absolute;
        inset:0;
      }
      .apf-coord-request-modal__dialog{
        position:relative;
        width:100%;
        max-width:760px;
        max-height:90vh;
        background:#fff;
        border-radius:18px;
        box-shadow:0 28px 60px rgba(15,23,42,.45);
        display:flex;
        flex-direction:column;
        overflow:hidden;
      }
      .apf-coord-request-modal__head{
        padding:16px 20px;
        border-bottom:1px solid #e4e7ec;
        display:flex;
        align-items:center;
        justify-content:space-between;
      }
      .apf-coord-request-modal__head h4{
        margin:0;
        font-size:18px;
        color:#0f172a;
      }
      .apf-coord-request-modal__close{
        border:none;
        background:transparent;
        font-size:26px;
        cursor:pointer;
        color:#94a3b8;
      }
      .apf-coord-request-modal__content{
        padding:20px;
        overflow:auto;
        flex:1;
      }
      .apf-coord-reject{
        position:absolute;
        inset:0;
        background:rgba(15,23,42,0.45);
        display:flex;
        align-items:center;
        justify-content:center;
        padding:20px;
        transition:opacity .2s ease;
        opacity:0;
        pointer-events:none;
        z-index:10;
      }
      .apf-coord-reject[aria-hidden="false"]{
        opacity:1;
        pointer-events:auto;
      }
      .apf-coord-reject__box{
        width:min(480px, calc(100vw - 32px));
        max-height:90vh;
        background:#fff;
        border-radius:20px;
        padding:28px;
        box-shadow:0 28px 60px rgba(15,23,42,0.55);
        display:flex;
        flex-direction:column;
        gap:18px;
        border:1px solid #e4e7ec;
      }
      .apf-coord-reject__header{
        display:flex;
        flex-direction:column;
        gap:6px;
      }
      .apf-coord-reject__box h5{
        margin:0;
        font-size:20px;
        color:#0f172a;
      }
      .apf-coord-reject__subtitle{
        margin:0;
        font-size:14px;
        line-height:1.5;
        color:#475467;
      }
      .apf-coord-reject__box textarea{
        width: 90%;
        min-height:130px;
        padding:12px 14px;
        border:1px solid #cbd5f5;
        border-radius:12px;
        font-size:14px;
        font-family:inherit;
        resize:vertical;
        line-height:1.5;
      }
      .apf-coord-reject__box textarea:focus{
        border-color:#6366f1;
        outline:none;
        box-shadow:0 0 0 3px rgba(99,102,241,0.2);
      }
      .apf-coord-reject__actions{
        display:flex;
        justify-content:flex-end;
        gap:12px;
        flex-wrap:wrap;
      }
      .apf-coord-request-modal__empty{
        margin:0;
        font-size:14px;
        color:#475467;
      }
      .apf-coord-request-detail__message{
        margin:0 0 12px;
        font-size:13px;
        color:#475467;
        line-height:1.5;
        padding:10px 12px;
        background:#f8fafc;
        border-radius:12px;
        border:1px solid #e4e7ec;
      }
      .apf-coord-request-detail__list{
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-coord-request-detail__footer{
        margin-top:16px;
        padding-top:12px;
        border-top:1px solid #e4e7ec;
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-coord-request__resend{
        display:flex;
        flex-direction:column;
        gap:8px;
      }
      .apf-coord-request__resend[hidden],
      .apf-coord-request-detail__status[data-pending-warning][hidden]{
        display:none !important;
      }
      .apf-coord-request-detail__status{
        margin:0;
        font-size:13px;
        color:#475467;
      }
      .apf-coord-request-detail__status--success{
        color:#15803d;
      }
      .apf-coord-request-detail__status--warning{
        color:#b45309;
      }
      .apf-coord-collab{
        border:1px solid #d0d5dd;
        border-radius:14px;
        background:#fff;
        overflow:hidden;
      }
      .apf-coord-collab--pending{
        border-color:#fde68a;
        background:#fffbeb;
      }
      .apf-coord-collab--approved{
        border-color:#bbf7d0;
        background:#f0fdf4;
      }
      .apf-coord-collab--rejected{
        border-color:#fecdd3;
        background:#fef2f2;
      }
      .apf-coord-collab__header{
        padding:14px 16px;
        display:flex;
        flex-direction:column;
        gap:10px;
      }
      .apf-coord-collab__toggle{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        align-items:center;
        border:none;
        background:transparent;
        width:100%;
        text-align:left;
        cursor:pointer;
      }
      .apf-coord-collab__line{
        display:flex;
        flex-wrap:wrap;
        align-items:center;
        gap:8px;
        width:100%;
      }
      .apf-coord-collab__name{
        font-weight:600;
        font-size:15px;
        color:#0f172a;
        width:100%;
      }
      .apf-coord-collab__company{
        font-size:12px;
        color:#475467;
        display:block;
        width:100%;
        margin-top:2px;
      }
      .apf-coord-collab__value{
        font-size:13px;
        color:#0f172a;
        background:#e0f2fe;
        padding:2px 8px;
        border-radius:999px;
      }
      .apf-coord-collab__status-label{
        font-size:12px;
        font-weight:600;
        color:#475467;
      }
      .apf-coord-collab__status-bullet{
        margin-right:6px;
      }
      .apf-coord-collab__status-gap{
        display:inline-block;
        width:4px;
      }
      .apf-coord-collab--approved .apf-coord-collab__status-label{
        color:var(--apf-primary);
      }
      .apf-coord-collab--rejected .apf-coord-collab__status-label{
        color:#b91c1c;
      }
      .apf-coord-collab__actions{
        display:flex;
        flex-wrap:wrap;
        gap:10px;
      }
      .apf-coord-request__actions{
        display:flex;
        gap:10px;
        flex-wrap:nowrap;
        align-items:center;
      }
      .apf-coord-request__actions--inline{
        justify-content:flex-end;
      }
      .apf-coord-request__edit-link-form{
        margin-left:auto;
      }
      .apf-coord-request__actions .apf-coord-btn{
        width:auto;
        min-width:120px;
        flex:0 0 auto;
      }
      .apf-coord-edit-link{
        background:none;
        border:none;
        padding:0;
        font-size:12px;
        color:#0d47a1;
        text-decoration:underline;
        cursor:pointer;
      }
      .apf-coord-edit-link:hover,
      .apf-coord-edit-link:focus{
        color:#0a2f73;
        text-decoration:underline;
      }
      .apf-coord-request__note-noscript textarea{
        width:100%;
        min-height:70px;
        padding:8px 10px;
        border:1px solid #cbd5f5;
        border-radius:8px;
        font-size:13px;
        font-family:inherit;
        resize:vertical;
        background:#fff;
      }
      .apf-coord-request__note-noscript textarea:focus{
        border-color:#6366f1;
        outline:none;
        box-shadow:0 0 0 3px rgba(99,102,241,0.2);
      }
      .apf-coord-request__note-noscript{
        display:block;
        margin-top:8px;
        font-size:13px;
        color:#0f172a;
      }
      .apf-coord-request__note-hint{
        margin:0;
        font-size:12px;
        color:#475467;
      }
      .apf-coord-btn--ghost-danger{
        background:transparent !important;
        color:#b91c1c !important;
        border:1px solid #b91c1c !important;
      }
      .apf-coord-btn--ghost-danger:hover,
      .apf-coord-btn--ghost-danger:focus{
        background:
          linear-gradient(120deg,#ef4444,#b91c1c) padding-box,
          linear-gradient(120deg,#ef4444,#b91c1c) border-box !important;
        color:#fff !important;
        border:1px solid transparent !important;
        border-radius:12px !important;
        background-clip:padding-box,border-box;
      }
      .apf-coord-collab__note{
        margin:0;
        font-size:12px;
        color:#475467;
      }
      .apf-coord-collab__note-detail{
        margin:4px 0 0;
        font-size:12px;
        color:#b91c1c;
      }
      .apf-coord-collab__body{
        padding:16px;
        border-top:1px solid #e4e7ec;
      }
      .apf-coord-collab__meta{
        margin:0 0 8px;
        font-size:12px;
        color:#94a3b8;
      }
      .apf-coord-collab__section{
        margin-bottom:14px;
      }
      .apf-coord-collab__section h5{
        margin:0 0 6px;
        font-size:13px;
        color:#0f172a;
      }
      .apf-coord-collab__section dl{
        margin:0;
        display:grid;
        grid-template-columns:minmax(150px,0.45fr) 1fr;
        gap:8px 16px;
        padding:12px 14px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#f8fafc;
      }
      .apf-coord-collab__section dt{
        font-weight:600;
        font-size:12px;
        text-transform:uppercase;
        letter-spacing:.04em;
        color:#1e293b;
        padding-bottom:6px;
        border-bottom:1px solid #e2e8f0;
      }
      .apf-coord-collab__section dd{
        margin:0;
        font-size:13px;
        color:#0f172a;
        padding-bottom:6px;
        border-bottom:1px solid #e2e8f0;
      }
      .apf-coord-collab__section dl > dt:last-of-type,
      .apf-coord-collab__section dl > dd:last-of-type{
        border-bottom:none;
        padding-bottom:0;
      }
      .apf-coord-collab__description{
        white-space:pre-line;
      }
      .apf-coord-btn--success{
        background:linear-gradient(120deg,var(--apf-primary),var(--apf-primary-strong));
        border:1px solid rgba(18,87,145,.3);
        color:#fff;
      }
      .apf-coord-btn--success:hover{
        background:#fff;
        color:var(--apf-primary);
        border-color:var(--apf-primary);
      }
      .apf-coord-btn--danger{
        background:
          linear-gradient(120deg,#ef4444,#b91c1c) padding-box,
          linear-gradient(120deg,#ef4444,#b91c1c) border-box !important;
        border:1px solid transparent !important;
        border-radius:12px !important;
        color:#fff !important;
      }
      .apf-coord-btn--danger:hover{
        background:#fff !important;
        color:#b91c1c !important;
        border-color:#b91c1c !important;
      }
      .apf-coord-alerts__pill{
        align-self:flex-start;
        background:#eef2ff;
        color:#3730a3;
        border-radius:999px;
        padding:6px 10px;
        font-size:12px;
        font-weight:700;
        border:1px solid #c7d2fe;
      }
      .apf-coord-calendar{
        margin-top:16px;
        border:1px solid #e4e7ec;
        border-radius:16px;
        background:#f7f8fb;
        padding:16px;
      }
      .apf-coord-calendar__wrap{
        display:grid;
        grid-template-columns:1fr;
        gap:12px;
        align-items:start;
      }
      .apf-coord-calendar__wrap.has-compose{
        grid-template-columns:2fr 1fr;
      }
      .apf-coord-calendar__compose{
        border:1px dashed #d0d5dd;
        border-radius:14px;
        padding:14px;
        background:#fff;
        margin-bottom:0;
        display:none;
        flex-direction:column;
        gap:12px;
      }
      .apf-coord-calendar__compose[aria-hidden="false"]{
        display:flex;
      }
      .apf-coord-calendar__compose-head{
        display:flex;
        justify-content:space-between;
        gap:12px;
        align-items:flex-start;
      }
      .apf-coord-calendar__compose h4{
        margin:2px 0 6px;
        font-size:18px;
        color:#0f172a;
      }
      .apf-coord-calendar__eyebrow{
        margin:0;
        font-size:12px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.05em;
        color:#475467;
      }
      .apf-coord-calendar__form{
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-coord-calendar__form-grid{
        display:grid;
        grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
        gap:12px;
      }
      .apf-coord-calendar__field{
        display:flex;
        flex-direction:column;
        gap:6px;
        font-size:13px;
        color:#344054;
      }
      .apf-coord-calendar__field strong{
        font-size:16px;
        color:#0f172a;
      }
      .apf-coord-calendar__field input,
      .apf-coord-calendar__field select{
        border:1px solid #d0d5dd;
        border-radius:10px;
        padding:10px 12px;
        font-size:14px;
        background:#fff;
      }
      .apf-coord-calendar__field--wide{
        grid-column:1 / -1;
      }
      .apf-coord-calendar__form-hint{
        margin:0;
        font-size:12px;
        color:#667085;
      }
      .apf-coord-calendar__form-actions{
        display:flex;
        align-items:center;
        gap:12px;
        flex-wrap:wrap;
      }
      .apf-coord-calendar__filters{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        align-items:center;
        margin-bottom:8px;
      }
      .apf-coord-calendar__filter{
        border:1px solid #d0d5dd;
        border-radius:999px;
        padding:6px 12px;
        background:#fff;
        color:#0f172a;
        font-size:12px;
        font-weight:700;
        cursor:pointer;
        transition:background .15s ease,border-color .15s ease,color .15s ease;
      }
      .apf-coord-calendar__filter.is-active{
        background:#0f172a;
        color:#fff;
        border-color:#0f172a;
      }
      .apf-coord-calendar__body{
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-coord-calendar__legend{
        margin-top:4px;
        display:flex;
        gap:16px;
        flex-wrap:wrap;
        font-size:12px;
        color:#475467;
      }
      .apf-coord-calendar__day--selectable{
        cursor:pointer;
        box-shadow:0 0 0 1px transparent;
      }
      .apf-coord-calendar__day--selected{
        border-color:#0f172a;
        background:#e0f2fe;
        box-shadow:0 0 0 2px rgba(31,111,235,0.2);
      }
      .apf-coord-calendar__legend-dot{
        display:inline-block;
        width:10px;
        height:10px;
        border-radius:999px;
        margin-right:4px;
      }
      .apf-coord-calendar__legend-dot--providers{
        background:#1f6feb;
      }
      .apf-coord-calendar__legend-dot--providers-own{
        background:#7c3aed;
      }
      .apf-coord-calendar__inner{
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-coord-calendar__header{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
      }
      .apf-coord-calendar__header h4{
        margin:0;
        font-size:16px;
        color:#0f172a;
      }
      .apf-coord-calendar__nav{
        display:flex;
        gap:8px;
      }
      .apf-coord-calendar__btn{
        width:32px;
        height:32px;
        border-radius:8px;
        border:1px solid #d0d5dd;
        background:#fff;
        color:#0f172a;
        cursor:pointer;
      }
      .apf-coord-calendar__weekdays,
      .apf-coord-calendar__days{
        display:grid;
        grid-template-columns:repeat(7,minmax(32px,1fr));
        gap:6px;
      }
      .apf-coord-calendar__weekday{
        text-align:center;
        font-size:11px;
        font-weight:600;
        color:#667085;
        text-transform:uppercase;
      }
      .apf-coord-calendar__day{
        min-height:40px;
        border-radius:10px;
        border:1px solid #d0d5dd;
        background:#fff;
        font-weight:600;
        font-size:14px;
        color:#0f172a;
        display:flex;
        align-items:center;
        justify-content:center;
        position:relative;
      }
      .apf-coord-calendar__day[role="button"]{
        cursor:pointer;
      }
      .apf-coord-calendar__day--muted{
        opacity:.35;
      }
      .apf-coord-calendar__day--has-event{
        border-color:#1f6feb;
      }
      .apf-coord-calendar__day--group-providers{
        border-color:#1f6feb;
      }
      .apf-coord-calendar__day--group-providers-own{
        border-color:#7c3aed;
      }
      .apf-coord-calendar__day--group-coordinators{
        border-color:#f97316;
      }
      .apf-coord-calendar__day--group-mixed{
        border-color:#7c3aed;
      }
      .apf-coord-calendar__markers{
        position:absolute;
        bottom:6px;
        left:50%;
        transform:translateX(-50%);
        display:flex;
        gap:4px;
      }
      .apf-coord-calendar__marker{
        width:6px;
        height:6px;
        border-radius:999px;
        background:#1f6feb;
      }
      .apf-coord-calendar__marker--providers{
        background:#1f6feb;
      }
      .apf-coord-calendar__marker--coordinators{
        background:#f97316;
      }
      .apf-coord-calendar__marker--providers-own{
        background:#7c3aed;
      }
      .apf-coord-calendar__empty,
      .apf-coord-calendar__hint{
        font-size:13px;
        color:#475467;
        margin:8px 0 0;
      }
      .apf-coord-modal{
        position:fixed;
        inset:0;
        z-index:1000;
        display:flex;
        align-items:center;
        justify-content:center;
        visibility:hidden;
        opacity:0;
        pointer-events:none;
        transition:opacity .2s ease;
      }
      .apf-coord-modal[aria-hidden="false"]{
        visibility:visible;
        opacity:1;
        pointer-events:auto;
      }
      .apf-coord-modal__overlay{
        position:absolute;
        inset:0;
        background:rgba(15,23,42,.45);
      }
      .apf-coord-modal__dialog{
        position:relative;
        width:100%;
        max-width:640px;
        max-height:90vh;
        background:#fff;
        border-radius:18px;
        box-shadow:0 30px 60px rgba(15,23,42,.35);
        display:flex;
        flex-direction:column;
        overflow:hidden;
      }
      .apf-coord-modal__head{
        padding:16px 20px;
        border-bottom:1px solid #e4e7ec;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
      }
      .apf-coord-modal__head h4{
        margin:0;
        font-size:18px;
        color:#0f172a;
      }
      .apf-coord-modal__close{
        border:none;
        background:transparent;
        font-size:24px;
        line-height:1;
        cursor:pointer;
        color:#475467;
      }
      .apf-coord-modal__content{
        padding:16px 20px 24px;
        overflow-y:auto;
        flex:1;
        display:flex;
        flex-direction:column;
        gap:16px;
      }
      .apf-coord-modal__empty{
        margin:0;
        font-size:14px;
        color:#475467;
        text-align:center;
      }
      .apf-coord-modal__items{
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-coord-modal__event{
        border:1px solid #e4e7ec;
        border-radius:14px;
        padding:14px;
        background:#f8fafc;
        display:flex;
        flex-direction:column;
        gap:8px;
      }
      .apf-coord-modal__event h5{
        margin:0;
        font-size:15px;
        color:#0f172a;
      }
      .apf-coord-modal__meta{
        display:flex;
        flex-direction:column;
        gap:4px;
        font-size:13px;
        color:#475467;
      }
      .apf-coord-modal__meta strong{
        color:#0f172a;
      }
      .apf-coord-modal__collabs summary{
        cursor:pointer;
        font-size:13px;
        font-weight:700;
        color:#0f172a;
        list-style:none;
      }
      .apf-coord-modal__collabs summary::-webkit-details-marker{display:none}
      .apf-coord-modal__collab-list{
        list-style:none;
        margin:6px 0 0;
        padding:0;
        display:flex;
        flex-direction:column;
        gap:4px;
        font-size:13px;
        color:#0f172a;
      }
      .apf-coord-modal__collab-list.is-scroll{
        max-height:200px;
        overflow:auto;
        padding-right:6px;
      }
      .apf-coord-modal__collab-list li{
        padding:4px 0;
        border-bottom:1px solid #e5e7eb;
      }
      .apf-coord-modal__collab-list li:last-child{border-bottom:none}
      .apf-coord-alert-edit{
        position:fixed;
        inset:0;
        z-index:1400;
        display:flex;
        align-items:center;
        justify-content:center;
        background:rgba(15,23,42,.45);
        opacity:0;
        visibility:hidden;
        pointer-events:none;
        transition:opacity .2s ease;
      }
      .apf-coord-alert-edit[aria-hidden="false"]{
        opacity:1;
        visibility:visible;
        pointer-events:auto;
      }
      .apf-coord-alert-edit__overlay{
        position:absolute;
        inset:0;
      }
      .apf-coord-alert-edit__dialog{
        position:relative;
        width:min(440px, calc(100vw - 32px));
        background:#fff;
        border-radius:16px;
        border:1px solid #e4e7ec;
        box-shadow:0 26px 50px rgba(15,23,42,.35);
        padding:18px 20px;
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-coord-alert-edit__head h5{
        margin:0;
        font-size:17px;
        color:#0f172a;
      }
      .apf-coord-alert-edit__head p{
        margin:4px 0 0;
        font-size:13px;
        color:#475467;
      }
      .apf-coord-alert-edit__field{
        display:flex;
        flex-direction:column;
        gap:6px;
        font-size:13px;
        color:#344054;
      }
      .apf-coord-alert-edit__field input,
      .apf-coord-alert-edit__field select{
        border:1px solid #d0d5dd;
        border-radius:10px;
        padding:9px 10px;
        font-size:14px;
        background:#fff;
      }
      .apf-coord-alert-edit__actions{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        align-items:center;
        margin-top:6px;
      }
      .apf-coord-modal__actions{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        align-items:center;
      }
      .apf-coord-modal__edit{
        margin-top:8px;
        padding-top:10px;
        border-top:1px solid #e4e7ec;
        display:flex;
        flex-direction:column;
        gap:10px;
      }
      .apf-coord-modal__field{
        display:flex;
        flex-direction:column;
        gap:4px;
        font-size:13px;
        color:#344054;
      }
      .apf-coord-modal__field input,
      .apf-coord-modal__field select{
        border:1px solid #d0d5dd;
        border-radius:10px;
        padding:8px 10px;
        font-size:14px;
        background:#fff;
      }
      .apf-coord-modal__edit-actions{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        align-items:center;
      }
      @media(max-width:640px){
        .apf-coord-card{
          margin:16px;
          padding:20px;
        }
        .apf-coord-requests{
          padding:12px;
        }
        .apf-coord-requests__cards{
          grid-template-columns:1fr;
          gap:10px;
        }
        .apf-coord-request-card{
          padding:12px;
        }
        .apf-coord-request-card__header{
          flex-wrap:wrap;
        }
        .apf-coord-request-card__foot{
          flex-wrap:wrap;
          gap:8px;
        }
        .apf-coord-calendar__wrap,
        .apf-coord-calendar__wrap.has-compose{
          grid-template-columns:1fr;
        }
        .apf-coord-alerts__grid{
          grid-template-columns:1fr;
        }
        .apf-coord-alerts__head,
        .apf-coord-alerts__list-head{
          flex-direction:column;
          align-items:flex-start;
        }
        .apf-coord-calendar__weekdays,
        .apf-coord-calendar__days{
          grid-template-columns:repeat(7,minmax(28px,1fr));
        }
      }
      /* Tema e responsividade inspirados no portal do colaborador */
      .apf-coord-portal{
        --apf-primary:#125791;
        --apf-primary-strong:#0f456e;
        --apf-border:#d6e1ed;
        --apf-soft:#f6f9fc;
        --apf-muted:#5f6b7a;
        --apf-ink:#0f172a;
        --apf-focus:0 0 0 3px rgba(18,87,145,.18),0 0 0 6px rgba(18,87,145,.12);
        background:radial-gradient(circle at 0 0,rgba(18,87,145,.08),transparent 32%),radial-gradient(circle at 100% 16%,rgba(18,87,145,.06),transparent 30%),#f3f6fb;
        color:var(--apf-ink);
        padding:clamp(12px,2vw,26px);
        box-sizing:border-box;
        overflow-x:hidden;
      }
      .apf-coord-card{
        max-width:1100px;
        padding:clamp(18px,2.5vw,28px);
        margin:0 auto;
        background:linear-gradient(135deg,#fff,rgba(18,87,145,.03));
        border:1px solid var(--apf-border);
        box-shadow:0 12px 28px rgba(15,23,42,.12);
        box-sizing:border-box;
      }
      .apf-coord-card h2{
        color:var(--apf-ink);
        letter-spacing:.01em;
      }
      .apf-coord-card > p{
        color:var(--apf-muted);
      }
      .apf-coord-notice{
        box-shadow:0 8px 16px rgba(15,23,42,.05);
      }
      .apf-coord-status{
        box-shadow:0 8px 16px rgba(15,23,42,.05);
      }
      .apf-coord-edit-bar{
        flex-wrap:wrap;
      }
      .apf-coord-edit-btn{
        border-color:var(--apf-border);
        background:var(--apf-soft);
        color:var(--apf-primary);
        box-shadow:0 8px 16px rgba(15,23,42,.06);
      }
      .apf-coord-form{
        background:var(--apf-soft);
        border:1px solid var(--apf-border);
        border-radius:14px;
        padding:14px;
        box-shadow:inset 0 1px 0 #fff,0 8px 18px rgba(15,23,42,.06);
      }
      .apf-coord-form label{
        color:var(--apf-muted);
      }
      .apf-coord-form input,
      .apf-coord-form select{
        border-color:#cad6e4;
        background:#fdfefe;
        box-shadow:inset 0 1px 0 rgba(255,255,255,.6);
      }
      .apf-coord-form input:focus,
      .apf-coord-form select:focus{
        border-color:var(--apf-primary);
        box-shadow:var(--apf-focus);
        outline:none;
      }
      .apf-coord-btn{
        background:linear-gradient(120deg,var(--apf-primary),var(--apf-primary-strong));
        box-shadow:0 10px 22px rgba(18,87,145,.22);
        border:1px solid rgba(18,87,145,.3);
        transition:transform .15s ease,box-shadow .2s ease,background .2s ease;
      }
      .apf-coord-btn:hover{
        transform:translateY(-1px);
      }
      .apf-coord-btn:focus{
        outline:none;
        box-shadow:var(--apf-focus);
      }
      .apf-coord-btn--ghost{
        background:#fff;
        color:var(--apf-primary);
        border:1px solid var(--apf-border);
        box-shadow:none;
      }
      .apf-coord-btn--primary{
        background:linear-gradient(120deg,#0f172a,var(--apf-primary));
        color:#fff;
        border:1px solid rgba(18,87,145,.3);
      }
      .apf-coord-btn--primary:hover{
        background:#fff;
        color:var(--apf-primary);
        border-color:var(--apf-primary);
      }
      .apf-coord-summary{
        background:linear-gradient(135deg,#fff,var(--apf-soft));
        border-color:var(--apf-border);
      }
      .apf-coord-requests{
        background:linear-gradient(135deg,#fff,var(--apf-soft));
        border-color:var(--apf-border);
        box-shadow:0 10px 26px rgba(15,23,42,.08);
        box-sizing:border-box;
      }
      .apf-coord-requests__head,
      .apf-coord-requests__controls{
        gap:14px;
      }
      .apf-coord-requests__controls{
        flex-wrap:wrap;
        align-items:flex-start;
      }
      .apf-coord-requests__cards{
        grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
      }
      .apf-coord-request-card{
        box-shadow:0 10px 20px rgba(15,23,42,.08);
        width:100%;
        min-width:0;
        box-sizing:border-box;
      }
      .apf-coord-request-card__header{
        gap:10px;
      }
      .apf-coord-request-card__foot{
        gap:10px;
      }
      .apf-coord-requests__pager{
        gap:10px;
      }
      .apf-coord-calendar{
        border-color:var(--apf-border);
        background:#fff;
        box-shadow:0 10px 22px rgba(15,23,42,.08);
      }
      .apf-coord-calendar__filters{
        gap:10px;
        flex-wrap:wrap;
      }
      .apf-coord-calendar__filter{
        border-color:var(--apf-border);
        color:var(--apf-ink);
      }
      .apf-coord-calendar__filter.is-active{
        background:linear-gradient(120deg,var(--apf-primary),var(--apf-primary-strong));
        border-color:rgba(18,87,145,.4);
      }
      .apf-coord-calendar__body{
        gap:14px;
      }
      .apf-coord-calendar__header{
        flex-wrap:wrap;
        gap:10px;
      }
      .apf-coord-calendar__btn{
        border-color:var(--apf-border);
        color:var(--apf-ink);
      }
      .apf-coord-calendar__weekdays,
      .apf-coord-calendar__days{
        grid-template-columns:repeat(7,minmax(32px,1fr));
      }
      .apf-coord-calendar__day{
        border-color:var(--apf-border);
        background:#fff;
        box-shadow:inset 0 1px 0 rgba(255,255,255,.6);
      }
      .apf-coord-calendar__day--has-event{
        box-shadow:0 6px 14px rgba(18,87,145,.16);
      }
      .apf-coord-calendar__compose{
        border-color:var(--apf-border);
        box-shadow:0 8px 18px rgba(15,23,42,.06);
      }
      .apf-coord-calendar__legend{
        gap:10px;
      }
      .apf-coord-modal__dialog,
      .apf-coord-request-modal__dialog,
      .apf-coord-archive-modal__dialog,
      .apf-coord-alert-edit__dialog{
        width:min(960px,calc(100vw - 26px));
      }
      .apf-coord-collab__section dl{
        background:var(--apf-soft);
        border-color:var(--apf-border);
      }
      @media(max-width:980px){
        .apf-coord-card{
          padding:18px;
        }
        .apf-coord-requests__head{
          flex-direction:column;
        }
        .apf-coord-requests__head > div{
          width:100%;
        }
        .apf-coord-requests__controls{
          width:100%;
        }
        .apf-coord-request-card__header,
        .apf-coord-request-card__foot{
          flex-direction:column;
          align-items:flex-start;
        }
        .apf-coord-request-card__foot button{
          width:100%;
        }
        .apf-coord-calendar__wrap,
        .apf-coord-calendar__wrap.has-compose{
          grid-template-columns:1fr;
        }
        .apf-coord-calendar__compose{
          order:2;
        }
      }
      @media(max-width:760px){
        .apf-coord-card{
          margin:0 auto;
          border-radius:14px;
        }
        .apf-coord-requests{
          padding:12px;
        }
        .apf-coord-edit-bar{
          flex-direction:column;
          align-items:flex-start;
        }
        .apf-coord-calendar__legend{
          flex-direction:column;
          align-items:flex-start;
        }
        .apf-coord-collab__section dl{
          grid-template-columns:1fr;
        }
        .apf-coord-request-card__header strong{
          font-size:14px;
        }
        .apf-coord-collab__toggle{
          align-items:flex-start;
          gap:6px;
        }
        .apf-coord-collab__line{
          gap:6px;
        }
        .apf-coord-requests__cards{
          grid-template-columns:1fr;
          gap:10px;
        }
        .apf-coord-request-card{
          padding:12px;
        }
        .apf-coord-requests__pager{
          width:100%;
          justify-content:center;
          gap:16px;
        }
        .apf-coord-requests__filter{
          width:100%;
        }
        .apf-coord-requests__filter select{
          width:100%;
        }
        .apf-coord-calendar__header{
          flex-direction:column;
          align-items:flex-start;
        }
        .apf-coord-calendar__filters{
          width:100%;
        }
        .apf-coord-request-modal__dialog,
        .apf-coord-archive-modal__dialog,
        .apf-coord-alert-edit__dialog{
          max-height:92vh;
        }
      }
      @media(max-width:560px){
        .apf-coord-card{
          padding:16px;
        }
        .apf-coord-form{
          padding:12px;
        }
        .apf-coord-requests{
          padding:10px;
        }
        .apf-coord-requests__controls{
          justify-content:center;
        }
        .apf-coord-requests__pager{
          justify-content:center;
          gap:14px;
        }
        .apf-coord-requests__pager-btn{
          width:32px;
          min-width:32px;
          padding:6px;
          flex:0 0 auto;
          justify-content:center;
        }
        .apf-coord-btn,
        .apf-coord-btn--ghost,
        .apf-coord-btn--primary{
          width:100%;
          justify-content:center;
        }
        .apf-coord-requests__pager-btn{
          width:32px !important;
          min-width:32px !important;
          max-width:42px;
          padding:6px 0;
          border-color:currentColor;
        }
        .apf-coord-request-card{
          padding:10px;
        }
        .apf-coord-request-card__foot button{
          width:100%;
        }
        .apf-coord-request-modal__dialog{
          width:calc(100vw - 14px);
        }
        .apf-coord-request-modal__head{
          padding:12px 14px;
        }
        .apf-coord-request-modal__content{
          padding:12px;
        }
        .apf-coord-request-card__foot{
          align-items:stretch;
        }
        .apf-coord-calendar__weekdays,
        .apf-coord-calendar__days{
          grid-template-columns:repeat(7,minmax(26px,1fr));
          gap:4px;
        }
        .apf-coord-calendar__day{
          height:34px;
          font-size:12px;
        }
        .apf-coord-calendar__btn{
          width:30px;
          height:30px;
        }
        .apf-coord-modal__dialog,
        .apf-coord-request-modal__dialog,
        .apf-coord-archive-modal__dialog,
        .apf-coord-alert-edit__dialog{
          width:calc(100vw - 18px);
        }
      }
    </style>
    <script>
    (function(){
      const $$ = (selector, ctx) => Array.from((ctx || document).querySelectorAll(selector));
      const select = document.getElementById('apfCoordCourseSelect');
      const manual = document.getElementById('apfCoordCourseManual');
      const dismissCoordMessages = () => {
        document.querySelectorAll('.apf-coord-notice, .apf-coord-status').forEach(node=>{
          if(node && node.parentNode){
            node.parentNode.removeChild(node);
          }
        });
      };
      if(window.history.replaceState){
        try{
          const url = new URL(window.location.href);
          let changed = false;
          ['apf_coord_notice','apf_coord_status','apf_coord_req_notice','apf_coord_req_status','apf_coord_alert_notice','apf_coord_alert_status'].forEach(param=>{
            if(url.searchParams.has(param)){
              url.searchParams.delete(param);
              changed = true;
            }
          });
          if(changed){
            const clean = url.pathname + (url.search ? url.search : '') + url.hash;
            window.history.replaceState({}, document.title, clean);
          }
        }catch(_e){}
      }
      if(select && manual){
        const toggleManual = () => {
          if(select.value === '__manual__'){
            manual.style.display = '';
            manual.required = true;
          }else{
            manual.style.display = 'none';
            manual.required = false;
          }
        };
        select.addEventListener('change', toggleManual);
        toggleManual();
      }

      const editToggle = document.getElementById('apfCoordEditToggle');
      const form = document.querySelector('.apf-coord-form');
      if(editToggle && form){
        const lockables = form.querySelectorAll('[data-lockable="1"]');
        const submit = form.querySelector('.apf-coord-btn');
        const setLocked = (locked) => {
          lockables.forEach(input => { input.disabled = locked; });
          if (submit) {
            submit.disabled = locked;
          }
          form.setAttribute('data-editing', locked ? '0' : '1');
        };
        setLocked(true);
        editToggle.addEventListener('click', () => {
          const isEditing = form.getAttribute('data-editing') === '1';
          if (isEditing) {
            setLocked(true);
            editToggle.textContent = 'Editar dados';
            editToggle.setAttribute('aria-pressed', 'false');
          } else {
            dismissCoordMessages();
            setLocked(false);
            editToggle.textContent = 'Cancelar';
            editToggle.setAttribute('aria-pressed', 'true');
            const firstField = lockables.length ? lockables[0] : null;
            if (firstField) {
              firstField.focus();
            }
          }
        });
      }
      if(form){
        form.addEventListener('submit', dismissCoordMessages);
        form.addEventListener('input', dismissCoordMessages, { once:true });
      }
      const alertForm = document.getElementById('apfCoordAlertForm');
      if(alertForm){
        alertForm.addEventListener('submit', dismissCoordMessages);
        alertForm.addEventListener('input', dismissCoordMessages, { once:true });
      }
      let rejectModalForm = null;
      let rejectModalNoteInput = null;
      const rejectOverlay = document.getElementById('apfCoordRejectOverlay');
      const rejectTextarea = document.getElementById('apfCoordRejectMessage');
      const rejectConfirm = rejectOverlay ? rejectOverlay.querySelector('[data-reject-confirm]') : null;
      const rejectCancel = rejectOverlay ? rejectOverlay.querySelector('[data-reject-cancel]') : null;

      const openRejectOverlay = (form, noteInput) => {
        if(!rejectOverlay || !rejectTextarea){ return; }
        rejectModalForm = form;
        rejectModalNoteInput = noteInput;
        rejectTextarea.value = noteInput ? (noteInput.value || '') : '';
        rejectOverlay.setAttribute('aria-hidden','false');
        setTimeout(()=>rejectTextarea.focus(), 30);
      };

      const closeRejectOverlay = () => {
        if(!rejectOverlay || !rejectTextarea){ return; }
        rejectOverlay.setAttribute('aria-hidden','true');
        rejectTextarea.value = '';
        rejectModalForm = null;
        rejectModalNoteInput = null;
      };

      if(rejectCancel){
        rejectCancel.addEventListener('click', ()=>closeRejectOverlay());
      }
      if(rejectOverlay){
        rejectOverlay.addEventListener('click', e=>{
          if(e.target === rejectOverlay){
            closeRejectOverlay();
          }
        });
      }
      if(rejectConfirm){
        rejectConfirm.addEventListener('click', ()=>{
          if(!rejectModalForm || !rejectModalNoteInput){ closeRejectOverlay(); return; }
          const formToSubmit = rejectModalForm;
          const message = rejectTextarea.value ? rejectTextarea.value.trim() : '';
          if(!message){
            rejectTextarea.focus();
            alert('Informe o motivo da recusa antes de continuar.');
            return;
          }
          rejectModalNoteInput.value = message;
          formToSubmit.dataset.apfRejectReady = '1';
          const rejectBtn = formToSubmit.querySelector('button[name="apf_coord_request_action"][value="reject"]');
          closeRejectOverlay();
          if(formToSubmit && typeof formToSubmit.requestSubmit === 'function'){
            if(rejectBtn){ formToSubmit.requestSubmit(rejectBtn); }
            else{ formToSubmit.requestSubmit(); }
          }else if(rejectBtn){
            rejectBtn.click();
          }else{
            formToSubmit && formToSubmit.dispatchEvent(new Event('submit', { cancelable:true, bubbles:true }));
          }
        });
      }

      const updateCollabStatus = (form, payload) => {
        if(!form || !payload){ return; }
        const wrapper = form.closest('.apf-coord-collab');
        if(!wrapper){ return; }
        const detail = wrapper.closest('.apf-coord-request-detail');
        const isSubmitted = detail ? detail.getAttribute('data-submitted') === '1' : false;
        const requestIdInput = form.querySelector('input[name="apf_coord_request_id"]');
        const nonceInput = form.querySelector('input[name="apf_coord_request_nonce"]');
        const requestId = requestIdInput ? requestIdInput.value : '';
        const nonceVal = nonceInput ? nonceInput.value : '';
        const status = payload.status || '';
        const statusLabel = payload.status_label || payload.status || 'Pendente';
        const decisionLabel = payload.decision_label || '';
        const note = payload.note || '';
        wrapper.classList.remove('apf-coord-collab--pending','apf-coord-collab--approved','apf-coord-collab--rejected');
        if(status){ wrapper.classList.add('apf-coord-collab--' + status); }
        wrapper.dataset.status = status || '';
        const statusEl = wrapper.querySelector('.apf-coord-collab__status-label');
        if(statusEl){
          const statusText = statusEl.querySelector('[data-status-text]');
          if(statusText){
            statusText.textContent = statusLabel;
          }else{
            statusEl.textContent = statusLabel;
          }
        }
        const actions = wrapper.querySelector('.apf-coord-collab__actions');
        if(actions){
          actions.innerHTML = '';
          if(status === 'pending' || !status){
            const pendingForm = document.createElement('form');
            pendingForm.method = 'post';
            pendingForm.className = 'apf-coord-request__actions';
            if(nonceVal){
              const nonceField = document.createElement('input');
              nonceField.type = 'hidden';
              nonceField.name = 'apf_coord_request_nonce';
              nonceField.value = nonceVal;
              pendingForm.appendChild(nonceField);
            }
            if(requestId){
              const idField = document.createElement('input');
              idField.type = 'hidden';
              idField.name = 'apf_coord_request_id';
              idField.value = requestId;
              pendingForm.appendChild(idField);
            }
            const noteField = document.createElement('input');
            noteField.type = 'hidden';
            noteField.name = 'apf_coord_request_note';
            noteField.value = '';
            pendingForm.appendChild(noteField);

            const approveBtn = document.createElement('button');
            approveBtn.type = 'submit';
            approveBtn.name = 'apf_coord_request_action';
            approveBtn.value = 'approve';
            approveBtn.className = 'apf-coord-btn apf-coord-btn--success';
            approveBtn.textContent = 'Validar';

            const rejectBtn = document.createElement('button');
            rejectBtn.type = 'submit';
            rejectBtn.name = 'apf_coord_request_action';
            rejectBtn.value = 'reject';
            rejectBtn.className = 'apf-coord-btn apf-coord-btn--danger';
            rejectBtn.textContent = 'Recusar';

            pendingForm.appendChild(approveBtn);
            pendingForm.appendChild(rejectBtn);
            actions.appendChild(pendingForm);
            enhanceRequestForms(actions);
          } else {
            const p = document.createElement('p');
            p.className = 'apf-coord-collab__note';
            p.textContent = decisionLabel ? (statusLabel + ' — ' + decisionLabel) : statusLabel;
            actions.appendChild(p);
            if(status === 'rejected' && note){
              const detailNote = document.createElement('p');
              detailNote.className = 'apf-coord-collab__note-detail';
              const strong = document.createElement('strong');
              strong.textContent = 'Motivo:';
              detailNote.appendChild(strong);
              detailNote.appendChild(document.createTextNode(' ' + note));
              actions.appendChild(detailNote);
            }
            if(!isSubmitted && requestId && nonceVal){
              const revertForm = document.createElement('form');
              revertForm.method = 'post';
              revertForm.className = 'apf-coord-request__actions apf-coord-request__actions--inline apf-coord-request__edit-link-form';
              const nonceField = document.createElement('input');
              nonceField.type = 'hidden';
              nonceField.name = 'apf_coord_request_nonce';
              nonceField.value = nonceVal;
              revertForm.appendChild(nonceField);
              const idField = document.createElement('input');
              idField.type = 'hidden';
              idField.name = 'apf_coord_request_id';
              idField.value = requestId;
              revertForm.appendChild(idField);
              const noteField = document.createElement('input');
              noteField.type = 'hidden';
              noteField.name = 'apf_coord_request_note';
              noteField.value = '';
              revertForm.appendChild(noteField);
              const revertBtn = document.createElement('button');
              revertBtn.type = 'submit';
              revertBtn.name = 'apf_coord_request_action';
              revertBtn.value = 'revert';
              revertBtn.className = 'apf-coord-edit-link';
              revertBtn.setAttribute('data-edit-request','');
              revertBtn.textContent = 'Editar solicitação';
              revertForm.appendChild(revertBtn);
              actions.appendChild(revertForm);
              enhanceRequestForms(actions);
            }
          }
        }
      };

      const toggleVisibility = (node, show) => {
        if(!node){ return; }
        if(show){
          node.hidden = false;
          node.removeAttribute('hidden');
          node.style.display = '';
        }else{
          node.hidden = true;
          node.setAttribute('hidden','');
          node.style.display = 'none';
        }
      };

      const computePendingCount = (detail) => {
        if(!detail){ return 0; }
        const collabs = detail.querySelectorAll('.apf-coord-collab');
        let pending = 0;
        collabs.forEach(c=>{
          let status = c.dataset.status || '';
          if(!status){
            if(c.classList.contains('apf-coord-collab--pending')){ status = 'pending'; }
            else if(c.classList.contains('apf-coord-collab--approved')){ status = 'approved'; }
            else if(c.classList.contains('apf-coord-collab--rejected')){ status = 'rejected'; }
          }
          if(status === 'pending' || !status){
            pending++;
          }
          c.dataset.status = status || 'pending';
        });
        return pending;
      };

      const refreshRequestState = (detailOrForm) => {
        if(!detailOrForm){ return; }
        const detail = detailOrForm && detailOrForm.classList && detailOrForm.classList.contains('apf-coord-request-detail')
          ? detailOrForm
          : detailOrForm.closest ? detailOrForm.closest('.apf-coord-request-detail') : null;
        if(!detail){ return; }
        const pending = computePendingCount(detail);
        const warning = detail.querySelector('[data-pending-warning]');
        const resendForm = detail.querySelector('[data-resend-form]');
        const submitted = detail.getAttribute('data-submitted') === '1';
        toggleVisibility(warning, !submitted && pending > 0);
        if(!submitted){
          toggleVisibility(resendForm, pending === 0);
        }else{
          toggleVisibility(resendForm, false);
        }
        const detailId = detail.id || '';
        const groupId = detailId.replace('apfCoordRequestDetail-','');
        if(groupId){
          const card = document.querySelector('[data-request-modal="'+groupId+'"]');
          if(card){
            const badge = card.querySelector('.apf-coord-request-card__badge');
            const total = detail.querySelectorAll('.apf-coord-collab').length;
            let badgeLabel = 'Pendente';
            let badgeState = 'pending';
            if(submitted){
              badgeLabel = 'Concluído';
              badgeState = 'done';
            }else if(pending >= total){
              badgeLabel = 'Pendente';
              badgeState = 'pending';
            }else if(pending > 0){
              badgeLabel = 'Em andamento';
              badgeState = 'progress';
            }else{
              badgeLabel = 'Em andamento';
              badgeState = 'progress';
            }
            if(badge){ badge.textContent = badgeLabel; }
            card.setAttribute('data-status', badgeState);
            card.setAttribute('data-submitted', submitted ? '1' : '0');
          }
        }
      };

      const submitRequestFormAjax = async (form, submitter, actionValue) => {
        const hiddenNote = form.querySelector('input[name="apf_coord_request_note"]');
        const formData = new FormData(form);
        formData.append('apf_coord_ajax','1');
        if(actionValue){
          formData.set('apf_coord_request_action', actionValue);
        }
        const btn = submitter || form.querySelector('button[type="submit"]');
        if(btn){ btn.disabled = true; }
        const detailRef = form.closest('.apf-coord-request-detail');
        const fallbackDecision = new Date().toLocaleString('pt-BR');
        const fallbackPayload = {
          status: actionValue === 'reject' ? 'rejected' : (actionValue === 'revert' ? 'pending' : 'approved'),
          status_label: actionValue === 'reject' ? 'Recusado' : (actionValue === 'revert' ? 'Pendente' : 'Aprovado'),
          decision_label: actionValue === 'revert' ? '' : fallbackDecision,
          note: actionValue === 'reject' && hiddenNote ? (hiddenNote.value || '') : '',
        };
        try{
          const resp = await fetch(window.location.href, {
            method:'POST',
            body:formData,
            credentials:'same-origin',
            headers:{'X-Requested-With':'XMLHttpRequest'},
          });
          const raw = await resp.text();
          let data = null;
          try{ data = JSON.parse(raw); }catch(_e){}
          if(!resp.ok){
            const msg = (data && data.data && data.data.message) || raw || 'Não foi possível salvar. Tente novamente.';
            throw new Error(msg);
          }
          if(data && data.success === false){
            const msg = (data.data && data.data.message) || 'Não foi possível salvar. Tente novamente.';
            throw new Error(msg);
          }
          const payload = (data && data.data) ? data.data : fallbackPayload;
          updateCollabStatus(form, payload);
          dismissCoordMessages();
          refreshRequestState(detailRef || form);
        }catch(err){
          alert(err.message || 'Não foi possível salvar. Tente novamente.');
          if(hiddenNote && actionValue !== 'reject'){
            hiddenNote.value = '';
          }
          return false;
        }finally{
          if(btn){ btn.disabled = false; }
        }
        return true;
      };

      const enhanceRequestForms = (scope) => {
        const context = scope || document;
        $$('.apf-coord-request__actions', context).forEach(requestForm=>{
          if(requestForm.dataset.bound === '1'){ return; }
          requestForm.dataset.bound = '1';
          const hiddenNote = requestForm.querySelector('input[name="apf_coord_request_note"]');
          let lastSubmitAction = '';
          requestForm.querySelectorAll('button[name="apf_coord_request_action"]').forEach(btn=>{
            btn.addEventListener('click', ()=>{
              lastSubmitAction = btn.value;
            });
          });
        requestForm.addEventListener('submit', event=>{
          dismissCoordMessages();
          const submitter = event.submitter || null;
          const actionValue = submitter ? submitter.value : lastSubmitAction;
          if(actionValue === 'reject'){
              if(requestForm.dataset.apfRejectReady !== '1'){
                event.preventDefault();
                openRejectOverlay(requestForm, hiddenNote);
                return;
              }
              requestForm.dataset.apfRejectReady = '0';
            } else {
              requestForm.dataset.apfRejectReady = '0';
              if(hiddenNote){
                hiddenNote.value = '';
              }
            }
            event.preventDefault();
            submitRequestFormAjax(requestForm, submitter, actionValue);
          });
        });
      };
      enhanceRequestForms();

      const requestModal = document.getElementById('apfCoordRequestModal');
      const requestModalDialog = requestModal ? requestModal.querySelector('.apf-coord-request-modal__dialog') : null;
      const requestModalContent = document.getElementById('apfCoordRequestModalContent');
      const requestModalClose = requestModal ? $$('[data-req-close]', requestModal) : [];
      const requestCache = document.querySelector('.apf-coord-request-cache');
      const requestButtons = $$('[data-request-modal]');
      const requestCardsContainer = document.querySelector('.apf-coord-requests__cards');
      const requestMonthSelect = document.getElementById('apfCoordReqMonth');
      let requestCards = requestCardsContainer ? Array.from(requestCardsContainer.querySelectorAll('.apf-coord-request-card')) : [];
      const requestPagerPrev = document.getElementById('apfCoordReqPrev');
      const requestPagerNext = document.getElementById('apfCoordReqNext');
      const requestPagerLabel = document.getElementById('apfCoordReqLabel');
      const requestEmptyState = document.getElementById('apfCoordReqEmpty');
      let requestModalLastFocus = null;
      let activeRequestDetail = null;
      const archiveToggle = document.getElementById('apfCoordArchiveToggle');
      const archiveModal = document.getElementById('apfCoordArchiveModal');
      const archiveDialog = archiveModal ? archiveModal.querySelector('.apf-coord-archive-modal__dialog') : null;
      const archiveCloseButtons = archiveModal ? $$('[data-archive-close]', archiveModal) : [];
      const archiveEntries = archiveModal ? $$('.apf-coord-archive-entry', archiveModal) : [];
      let archiveLastFocus = null;

      const bindCollabToggles = (scope) => {
        if(!scope){ return; }
        $$('.apf-coord-collab__toggle', scope).forEach(btn=>{
          if(btn.dataset.bound === '1'){ return; }
          btn.dataset.bound = '1';
          btn.addEventListener('click', ()=>{
            const targetId = btn.getAttribute('data-collab-toggle');
            if(!targetId){ return; }
            const panel = scope.querySelector('[data-collab-panel="'+targetId+'"]') || scope.querySelector('#'+targetId);
            if(!panel){ return; }
            const expanded = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            panel.hidden = expanded;
          });
        });
        enhanceRequestForms(scope);
      };

      const updateBodyScrollLock = () => {
        const requestOpen = requestModal && requestModal.getAttribute('aria-hidden') === 'false';
        const archiveOpen = archiveModal && archiveModal.getAttribute('aria-hidden') === 'false';
        document.body.style.overflow = (requestOpen || archiveOpen) ? 'hidden' : '';
      };

      const REQUEST_PAGE_SIZE = 6;
      let requestPage = 0;
      const renderRequestPager = (reset = false) => {
        if(!requestCardsContainer){ return; }
        if(reset){ requestPage = 0; }
        const monthFilter = requestMonthSelect ? requestMonthSelect.value : '';
        const filtered = [];
        requestCards.forEach(card=>{
          const month = card.getAttribute('data-coord-month') || '';
          if(!monthFilter || month === monthFilter){
            filtered.push(card);
          }else{
            card.classList.add('apf-coord-requests__hidden');
          }
        });
        const total = filtered.length;
        const totalPages = total ? Math.ceil(total / REQUEST_PAGE_SIZE) : 0;
        if(requestPage >= totalPages){ requestPage = Math.max(0, totalPages - 1); }
        requestCards.forEach(card=>card.classList.add('apf-coord-requests__hidden'));
        filtered.forEach((card, idx)=>{
          const hide = totalPages === 0 || idx < (requestPage * REQUEST_PAGE_SIZE) || idx >= ((requestPage + 1) * REQUEST_PAGE_SIZE);
          card.classList.toggle('apf-coord-requests__hidden', hide);
        });
        if(requestEmptyState){
          requestEmptyState.hidden = total !== 0;
        }
        if(requestPagerLabel){
          requestPagerLabel.textContent = totalPages ? ((requestPage + 1) + '/' + totalPages) : '0/0';
        }
        if(requestPagerPrev){
          requestPagerPrev.disabled = totalPages === 0 || requestPage === 0;
        }
        if(requestPagerNext){
          requestPagerNext.disabled = totalPages === 0 || requestPage >= (totalPages - 1);
        }
      };

      if(requestMonthSelect){
        requestMonthSelect.addEventListener('change', ()=>renderRequestPager(true));
      }

      if(requestPagerPrev){
        requestPagerPrev.addEventListener('click', ()=>{
          if(requestPage > 0){
            requestPage -= 1;
            renderRequestPager(false);
          }
        });
      }
      if(requestPagerNext){
        requestPagerNext.addEventListener('click', ()=>{
          const totalPages = requestCards.length ? Math.ceil(requestCards.length / REQUEST_PAGE_SIZE) : 0;
          if(requestPage < (totalPages - 1)){
            requestPage += 1;
            renderRequestPager(false);
          }
        });
      }
      renderRequestPager(true);

      const closeRequestModal = () => {
        if(!requestModal){ return; }
        closeRejectOverlay();
        requestModal.setAttribute('aria-hidden','true');
        updateBodyScrollLock();
        if(activeRequestDetail && requestCache){
          activeRequestDetail.hidden = true;
          requestCache.appendChild(activeRequestDetail);
          activeRequestDetail = null;
        }
        if(requestModalLastFocus){
          requestModalLastFocus.focus();
        }
      };

      const openRequestModal = (groupId) => {
        if(!requestModal || !requestModalContent || !requestCache){ return; }
        const detail = document.getElementById('apfCoordRequestDetail-' + groupId);
        if(!detail){ return; }
        if(activeRequestDetail && requestCache.contains(activeRequestDetail) === false){
          requestCache.appendChild(activeRequestDetail);
          activeRequestDetail.hidden = true;
        }
        activeRequestDetail = detail;
        detail.hidden = false;
        requestModalContent.innerHTML = '';
        requestModalContent.appendChild(detail);
        refreshRequestState(detail.querySelector('form.apf-coord-request__actions'));
        bindCollabToggles(detail);
        requestModal.setAttribute('aria-hidden','false');
        requestModalLastFocus = document.activeElement;
        updateBodyScrollLock();
        if(requestModalDialog){
          requestModalDialog.setAttribute('tabindex','-1');
          requestModalDialog.focus();
        }
      };

      requestButtons.forEach(btn=>{
        btn.addEventListener('click', ()=>{
          const target = btn.getAttribute('data-request-modal');
          if(target){
            openRequestModal(target);
          }
        });
      });
      requestModalClose.forEach(btn=>{
        btn.addEventListener('click', ()=>closeRequestModal());
      });
      const closeArchiveModal = () => {
        if(!archiveModal){ return; }
        archiveModal.setAttribute('aria-hidden','true');
        updateBodyScrollLock();
        if(archiveToggle){
          archiveToggle.setAttribute('aria-expanded','false');
        }
        archiveEntries.forEach(entry=>{
          const toggleBtn = entry.querySelector('[data-archive-toggle]');
          const panel = entry.querySelector('[data-archive-panel]');
          if(toggleBtn){
            toggleBtn.setAttribute('aria-expanded','false');
          }
          if(panel){
            panel.hidden = true;
          }
        });
        if(archiveLastFocus){
          archiveLastFocus.focus();
          archiveLastFocus = null;
        }
      };
      const openArchiveModal = () => {
        if(!archiveModal){ return; }
        archiveLastFocus = document.activeElement;
        archiveModal.setAttribute('aria-hidden','false');
        updateBodyScrollLock();
        if(archiveToggle){
          archiveToggle.setAttribute('aria-expanded','true');
        }
        if(archiveDialog){
          archiveDialog.setAttribute('tabindex','-1');
          archiveDialog.focus();
        }
      };
      if(archiveToggle && archiveModal){
        archiveToggle.addEventListener('click', ()=>{
          const hidden = archiveModal.getAttribute('aria-hidden') !== 'false';
          if(hidden){
            openArchiveModal();
          }else{
            closeArchiveModal();
          }
        });
      }
      archiveCloseButtons.forEach(btn=>{
        btn.addEventListener('click', ()=>closeArchiveModal());
      });
      if(archiveEntries.length){
        archiveEntries.forEach(entry=>{
          const toggleBtn = entry.querySelector('[data-archive-toggle]');
          const panel = entry.querySelector('[data-archive-panel]');
          if(!toggleBtn || !panel){ return; }
          toggleBtn.addEventListener('click', ()=>{
            const expanded = toggleBtn.getAttribute('aria-expanded') === 'true';
            if(expanded){
              toggleBtn.setAttribute('aria-expanded','false');
              panel.hidden = true;
              return;
            }
            archiveEntries.forEach(other=>{
              if(other === entry){ return; }
              const otherToggle = other.querySelector('[data-archive-toggle]');
              const otherPanel = other.querySelector('[data-archive-panel]');
              if(otherToggle){
                otherToggle.setAttribute('aria-expanded','false');
              }
              if(otherPanel){
                otherPanel.hidden = true;
              }
            });
            toggleBtn.setAttribute('aria-expanded','true');
            panel.hidden = false;
            bindCollabToggles(panel);
          });
        });
      }
      document.addEventListener('keydown', e=>{
        if(e.key === 'Escape' && rejectOverlay && rejectOverlay.getAttribute('aria-hidden') === 'false'){
          e.preventDefault();
          closeRejectOverlay();
          return;
        }
        if(e.key === 'Escape' && alertEditOverlay && alertEditOverlay.getAttribute('aria-hidden') === 'false'){
          e.preventDefault();
          closeAlertEditOverlay();
          return;
        }
        if(e.key === 'Escape' && requestModal && requestModal.getAttribute('aria-hidden') === 'false'){
          e.preventDefault();
          closeRequestModal();
          return;
        }
        if(e.key === 'Escape' && archiveModal && archiveModal.getAttribute('aria-hidden') === 'false'){
          e.preventDefault();
          closeArchiveModal();
        }
      });

      const calendarNode = document.getElementById('apfCoordCalendar');
      const coordModal = document.getElementById('apfCoordModal');
      const coordModalTitle = document.getElementById('apfCoordModalTitle');
      const coordModalItems = coordModal ? coordModal.querySelector('.apf-coord-modal__items') : null;
      const coordModalEmpty = coordModal ? coordModal.querySelector('.apf-coord-modal__empty') : null;
      const coordModalClose = coordModal ? $$('[data-modal-close]', coordModal) : [];
      const composeBox = document.getElementById('apfCoordCompose');
      const calendarWrap = calendarNode ? calendarNode.querySelector('.apf-coord-calendar__wrap') : null;
      const composeForm = document.getElementById('apfCoordAlertForm');
      const composeDateInput = document.getElementById('apfCoordComposeDate');
      const composeDateLabel = document.getElementById('apfCoordComposeDateLabel');
      const composeTarget = document.getElementById('apfCoordComposeTarget');
      const composeAction = document.getElementById('apfCoordComposeAction');
      const composeEvent = document.getElementById('apfCoordComposeEvent');
      const composeDelete = document.getElementById('apfCoordComposeDelete');
      const composeMessage = composeForm ? composeForm.querySelector('input[name="apf_coord_alert_title"]') : null;
      const composeEvents = calendarNode ? JSON.parse(calendarNode.getAttribute('data-compose-events') || '[]') : [];
      const currentUserId = calendarNode ? parseInt(calendarNode.getAttribute('data-user-id') || '0', 10) || 0 : 0;
      let coordModalLastFocus = null;
      let coordModalDate = '';
      const alertEditOverlay = document.getElementById('apfCoordAlertEdit');
      const alertEditTarget = document.getElementById('apfCoordAlertEditTarget');
      const alertEditMessage = document.getElementById('apfCoordAlertEditMessage');
      const alertEditSave = alertEditOverlay ? alertEditOverlay.querySelector('[data-alert-edit-save]') : null;
      const alertEditCancel = alertEditOverlay ? alertEditOverlay.querySelector('[data-alert-edit-cancel]') : null;
      const alertEditClose = alertEditOverlay ? alertEditOverlay.querySelectorAll('[data-alert-edit-close]') : [];
      let alertEditPayload = null;
      const ensureTargetOption = (token, label) => {
        if(!composeTarget || !token){ return; }
        const exists = Array.from(composeTarget.options || []).some(opt => opt.value === token);
        if(!exists){
          const opt = document.createElement('option');
          opt.value = token;
          opt.textContent = label || token;
          composeTarget.appendChild(opt);
        }
      };
      const submitComposeAction = (action, payload) => {
        if(!composeForm){ alert('Não foi possível carregar o formulário de avisos.'); return; }
        if(composeAction){ composeAction.value = action || 'add'; }
        if(composeEvent){ composeEvent.value = (payload && payload.id) ? payload.id : ''; }
        if(composeDateInput && payload && payload.date){ composeDateInput.value = payload.date; }
        if(composeTarget && payload && payload.token){
          ensureTargetOption(payload.token, payload.token);
          composeTarget.value = payload.token;
        }
        if(composeMessage && payload && typeof payload.title === 'string'){
          composeMessage.value = payload.title;
        }
        if(typeof composeForm.requestSubmit === 'function'){
          composeForm.requestSubmit();
        }else{
          composeForm.submit();
        }
      };
      const openAlertEditOverlay = (payload) => {
        if(!alertEditOverlay || !alertEditTarget || !alertEditMessage){ return; }
        alertEditPayload = payload || null;
        if(alertEditTarget && composeTarget){
          alertEditTarget.innerHTML = '';
          composeTarget.querySelectorAll('option').forEach(opt=>{
            alertEditTarget.appendChild(opt.cloneNode(true));
          });
        }
        alertEditTarget.value = (payload && payload.token) ? payload.token : '';
        alertEditMessage.value = (payload && payload.title) ? payload.title : '';
        alertEditOverlay.setAttribute('aria-hidden','false');
        setTimeout(()=>{
          (alertEditMessage.value ? alertEditMessage : alertEditTarget).focus();
        }, 20);
      };
      const closeAlertEditOverlay = () => {
        if(!alertEditOverlay){ return; }
        alertEditOverlay.setAttribute('aria-hidden','true');
        alertEditPayload = null;
      };
      if(alertEditCancel){
        alertEditCancel.addEventListener('click', closeAlertEditOverlay);
      }
      if(alertEditClose && alertEditClose.length){
        alertEditClose.forEach(btn=>{
          btn.addEventListener('click', closeAlertEditOverlay);
        });
      }
      if(alertEditOverlay){
        alertEditOverlay.addEventListener('click', (e)=>{
          if(e.target === alertEditOverlay){
            closeAlertEditOverlay();
          }
        });
      }
      if(alertEditSave){
        alertEditSave.addEventListener('click', ()=>{
          if(!alertEditPayload){ closeAlertEditOverlay(); return; }
          const token = alertEditTarget ? alertEditTarget.value : '';
          const title = alertEditMessage ? alertEditMessage.value.trim() : '';
          if(!token || !title){
            alert('Preencha o colaborador e a mensagem.');
            return;
          }
          submitComposeAction('update', {
            id: alertEditPayload.id || '',
            date: alertEditPayload.date || '',
            token,
            title,
          });
          closeAlertEditOverlay();
        });
      }
      if(composeDelete && composeForm){
        composeDelete.addEventListener('click', ()=>{
          if(!composeEvent || !composeEvent.value){ return; }
          const confirmDelete = window.confirm('Deseja excluir este aviso?');
          if(!confirmDelete){ return; }
          if(composeAction){ composeAction.value = 'delete'; }
          if(typeof composeForm.requestSubmit === 'function'){
            composeForm.requestSubmit();
          }else{
            composeForm.submit();
          }
        });
      }

      if(calendarNode){
        const MONTH_NAMES = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
        const WEEKDAYS = ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];
        let monthDate = new Date();
        monthDate.setDate(1);
        let activeFilter = 'coordinators';
        let composeDate = composeDateInput ? composeDateInput.value : '';

        const eventsByDate = new Map();
        const groupLabels = {
          providers: 'Colaboradores',
          coordinators: 'Coordenadores',
        };
        const legend = document.getElementById('apfCoordLegend');
        const updateLegend = () => {
          if(!legend){ return; }
          const showProviders = activeFilter === 'providers';
          legend.style.display = showProviders ? '' : 'none';
        };

        const formatDateBr = (value) => {
          if(!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)){ return value || '—'; }
          return value.slice(8,10) + '/' + value.slice(5,7) + '/' + value.slice(0,4);
        };

        const setComposeDate = (iso) => {
          if(!iso){ return; }
          composeDate = iso;
          if(composeDateInput){ composeDateInput.value = iso; }
          if(composeDateLabel){ composeDateLabel.textContent = formatDateBr(iso); }
          renderCalendar();
        };

        const resetComposeMode = () => {
          if(composeAction){ composeAction.value = 'add'; }
          if(composeEvent){ composeEvent.value = ''; }
          if(composeDelete){ composeDelete.hidden = true; }
          if(composeForm){ composeForm.querySelector('button[type="submit"]').textContent = 'Enviar aviso'; }
          if(composeMessage){ composeMessage.value = ''; }
          if(composeTarget){ composeTarget.value = ''; }
        };

        const enterEditMode = (payload) => {
          if(!payload){ return; }
          if(composeAction){ composeAction.value = 'update'; }
          if(composeEvent && payload.id){ composeEvent.value = payload.id; }
          if(composeDelete){ composeDelete.hidden = false; }
          if(composeForm){ composeForm.querySelector('button[type="submit"]').textContent = 'Atualizar aviso'; }
          if(payload.date){ setComposeDate(payload.date); }
          if(composeTarget && payload.token){ composeTarget.value = payload.token; }
          if(composeMessage && payload.title){ composeMessage.value = payload.title; }
          if(calendarWrap){ calendarWrap.classList.add('has-compose'); }
          if(composeBox){
            composeBox.hidden = false;
            composeBox.setAttribute('aria-hidden','false');
          }
        };

        const toggleComposeBox = () => {
          if(!composeBox){ return; }
          const show = activeFilter === 'providers';
          composeBox.hidden = !show;
          composeBox.setAttribute('aria-hidden', show ? 'false' : 'true');
          composeBox.style.display = show ? '' : 'none';
          if(calendarWrap){
            calendarWrap.classList.toggle('has-compose', show);
          }
          if(!show){
            resetComposeMode();
          }
        };
        toggleComposeBox();

        const normalizeGroups = (groups) => {
          if(!Array.isArray(groups)){ return []; }
          return Array.from(
            new Set(
              groups
                .map(group => (group || '').toString().toLowerCase())
                .filter(group => group === 'providers' || group === 'coordinators')
            )
          );
        };

        const sanitizeList = (list) => {
          if(!Array.isArray(list)){ return []; }
          return list
            .map(item => (item || '').toString().trim())
            .filter(Boolean);
        };

        try{
          const raw = JSON.parse(calendarNode.getAttribute('data-events') || '[]');
          if(Array.isArray(raw)){
            raw.forEach(evt=>{
              if(!evt || !evt.date){ return; }
              const date = (evt.date || '').toString();
              if(!date){ return; }
              const normalizedGroups = normalizeGroups(evt.groups || []);
              const titles = {};
              if(evt.titles && typeof evt.titles === 'object'){
                Object.keys(evt.titles).forEach(key=>{
                  const normalizedKey = (key || '').toString().toLowerCase();
                  if(normalizedKey !== 'providers' && normalizedKey !== 'coordinators'){ return; }
                  const list = sanitizeList(evt.titles[key]);
                  if(list.length){
                    titles[normalizedKey] = list;
                  }
                });
              }

              const normalizedEvents = Array.isArray(evt.events)
                ? evt.events.map(entry=>{
                    if(!entry){ return null; }
                    const entryGroups = normalizeGroups(entry.groups || []);
                    const recipients = (entry.recipients && typeof entry.recipients === 'object') ? entry.recipients : {};
                    return {
                      id: (entry.id || '').toString(),
                      editable: !!entry.editable,
                      target_token: (entry.target_token || '').toString(),
                      created_by: entry.created_by || 0,
                      title: (entry.title || '').toString(),
                      groups: entryGroups.length ? entryGroups : (normalizedGroups.length ? normalizedGroups : ['providers']),
                      recipients: {
                        providers: sanitizeList(recipients.providers || []),
                        coordinators: sanitizeList(recipients.coordinators || []),
                      },
                    };
                  }).filter(item => item && (item.title || item.recipients.providers.length || item.recipients.coordinators.length))
                : [];

              const existing = eventsByDate.get(date) || { date, groups: [], titles: {}, events: [] };
              const mergedGroups = new Set([ ...(existing.groups || []), ...(normalizedGroups.length ? normalizedGroups : ['providers']) ]);
              existing.groups = Array.from(mergedGroups);
              existing.titles = existing.titles || {};
              Object.keys(titles).forEach(group=>{
                const prev = Array.isArray(existing.titles[group]) ? existing.titles[group] : [];
                existing.titles[group] = prev.concat(titles[group]);
              });
              if(normalizedEvents.length){
                existing.events = (existing.events || []).concat(normalizedEvents);
              }else{
                const fallbackTitle = (evt.title || '').toString();
                const fallbackRecipients = {
                  providers: sanitizeList(titles.providers || []),
                  coordinators: sanitizeList(titles.coordinators || []),
                };
                if(fallbackTitle || fallbackRecipients.providers.length || fallbackRecipients.coordinators.length){
                  existing.events = (existing.events || []).concat([{
                    title: fallbackTitle,
                    groups: normalizedGroups.length ? normalizedGroups : ['providers'],
                    recipients: fallbackRecipients,
                  }]);
                }
              }
              eventsByDate.set(date, existing);
            });
          }
        }catch(_e){}

        function buildTooltip(entry){
          if(!entry){ return ''; }
          const titles = entry.titles || {};
          const parts = [];
          (entry.groups || []).forEach(group=>{
            const safeGroup = (group === 'coordinators') ? 'coordinators' : 'providers';
            const label = groupLabels[safeGroup] || safeGroup;
            const list = Array.isArray(titles[safeGroup]) ? titles[safeGroup].filter(Boolean) : [];
            if(list.length){
              parts.push(label + ': ' + list.join('; '));
            }else{
              parts.push(label);
            }
          });
          return parts.join(' | ');
        }

        const filterButtons = calendarNode.querySelectorAll('[data-calendar-filter]');
        if(filterButtons.length){
          filterButtons.forEach(btn=>{
            btn.addEventListener('click', ()=>{
              const key = btn.getAttribute('data-calendar-filter') || '';
              if(key !== 'providers' && key !== 'coordinators'){ return; }
              activeFilter = key;
              filterButtons.forEach(other=>other.classList.toggle('is-active', other === btn));
              toggleComposeBox();
              updateLegend();
              renderCalendar();
            });
          });
        }
        updateLegend();

        function renderCoordModal(date, info, groupsFilter){
          if(!coordModalItems || !coordModalEmpty){ return; }
          coordModalDate = date;
          if(coordModalTitle){
            coordModalTitle.textContent = 'Avisos de ' + formatDateBr(date);
          }
          coordModalItems.innerHTML = '';
          const visibleGroups = Array.isArray(groupsFilter) && groupsFilter.length
            ? groupsFilter
            : (Array.isArray(info.groups) ? info.groups : []);
          const entries = Array.isArray(info.events)
            ? info.events.filter(entry => {
                const entryGroups = Array.isArray(entry.groups) ? entry.groups : [];
                return entryGroups.some(g => visibleGroups.includes(g));
              })
            : [];
          if(!entries.length){
            coordModalEmpty.hidden = false;
            return;
          }
          coordModalEmpty.hidden = true;
          entries.forEach(entry=>{
            if(!entry){ return; }
            const card = document.createElement('article');
            card.className = 'apf-coord-modal__event';
            const heading = document.createElement('h5');
            heading.textContent = entry.title || 'Aviso';
            card.appendChild(heading);

            const meta = document.createElement('div');
            meta.className = 'apf-coord-modal__meta';

            const providersList = entry.recipients && Array.isArray(entry.recipients.providers)
              ? entry.recipients.providers.filter(Boolean)
              : [];

            if(providersList.length){
              const acc = document.createElement('details');
              acc.className = 'apf-coord-modal__collabs';
              const summary = document.createElement('summary');
              summary.textContent = 'Colaboradores (' + providersList.length + ')';
              acc.appendChild(summary);
              const list = document.createElement('ul');
              list.className = 'apf-coord-modal__collab-list' + (providersList.length > 10 ? ' is-scroll' : '');
              providersList.forEach(raw=>{
                const li = document.createElement('li');
                li.textContent = raw;
                list.appendChild(li);
              });
              acc.appendChild(list);
              meta.appendChild(acc);
            } else {
              const row = document.createElement('div');
              row.textContent = 'Nenhum colaborador listado.';
              meta.appendChild(row);
            }

            card.appendChild(meta);
            if(entry.editable){
              const actions = document.createElement('div');
              actions.className = 'apf-coord-modal__actions';
              const editBtn = document.createElement('button');
              editBtn.type = 'button';
              editBtn.className = 'apf-coord-btn apf-coord-btn--ghost';
              editBtn.textContent = 'Editar';
              const removeBtn = document.createElement('button');
              removeBtn.type = 'button';
              removeBtn.className = 'apf-coord-btn apf-coord-btn--ghost-danger';
              removeBtn.textContent = 'Remover';
              actions.appendChild(editBtn);
              actions.appendChild(removeBtn);
              card.appendChild(actions);

              editBtn.addEventListener('click', ()=>{
                openAlertEditOverlay({
                  id: entry.id || '',
                  date,
                  token: entry.target_token || '',
                  title: entry.title || '',
                });
              });
              removeBtn.addEventListener('click', ()=>{
                if(!window.confirm('Deseja remover este aviso?')){ return; }
                const token = entry.target_token || '';
                const title = entry.title || 'Aviso';
                submitComposeAction('delete', { id: entry.id || '', date, token, title });
              });
            }
            coordModalItems.appendChild(card);
          });
        }

        function openCoordModal(date, groupsFilter){
          if(!coordModal){ return; }
          const info = eventsByDate.get(date);
          if(!info){ return; }
          renderCoordModal(date, info, groupsFilter);
          coordModalLastFocus = document.activeElement;
          coordModal.setAttribute('aria-hidden','false');
          document.body.style.overflow = 'hidden';
          const focusTarget = coordModal.querySelector('.apf-coord-modal__close') || coordModal;
          if(focusTarget){
            focusTarget.focus();
          }
        }

        function closeCoordModal(){
          if(!coordModal){ return; }
          coordModal.setAttribute('aria-hidden','true');
          document.body.style.overflow = '';
          if(coordModalLastFocus){
            coordModalLastFocus.focus();
          }
        }

        if(coordModalClose.length){
          coordModalClose.forEach(btn=>{
            btn.addEventListener('click', closeCoordModal);
          });
        }
        if(coordModal){
          coordModal.addEventListener('keydown', (e)=>{
            if(e.key === 'Escape'){
              e.preventDefault();
              closeCoordModal();
            }
          });
        }
        document.addEventListener('keydown', (e)=>{
          if(e.key === 'Escape' && coordModal && coordModal.getAttribute('aria-hidden') === 'false'){
            e.preventDefault();
            closeCoordModal();
          }
        });

        const body = calendarNode.querySelector('.apf-coord-calendar__body');

        function renderCalendar(){
          if(!body){ return; }
          const container = document.createElement('div');
          container.className = 'apf-coord-calendar__inner';

          const header = document.createElement('div');
          header.className = 'apf-coord-calendar__header';

          const title = document.createElement('h4');
          const monthIndex = monthDate.getMonth();
          const year = monthDate.getFullYear();
          title.textContent = MONTH_NAMES[monthIndex] + ' ' + year;

          const nav = document.createElement('div');
          nav.className = 'apf-coord-calendar__nav';

          const prev = document.createElement('button');
          prev.type = 'button';
          prev.className = 'apf-coord-calendar__btn';
          prev.innerHTML = '&larr;';
          prev.addEventListener('click', ()=>{
            monthDate.setMonth(monthDate.getMonth() - 1, 1);
            renderCalendar();
          });

          const next = document.createElement('button');
          next.type = 'button';
          next.className = 'apf-coord-calendar__btn';
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
          weekRow.className = 'apf-coord-calendar__weekdays';
          WEEKDAYS.forEach(day=>{
            const span = document.createElement('span');
            span.className = 'apf-coord-calendar__weekday';
            span.textContent = day;
            weekRow.appendChild(span);
          });

          const daysGrid = document.createElement('div');
          daysGrid.className = 'apf-coord-calendar__days';
          const firstWeekday = (monthDate.getDay() + 6) % 7;
          const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
          const totalCells = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;

          for(let cell = 0; cell < totalCells; cell++){
            const dayNumber = cell - firstWeekday + 1;
            const div = document.createElement('div');
            div.className = 'apf-coord-calendar__day';

            if(dayNumber < 1 || dayNumber > daysInMonth){
              div.classList.add('apf-coord-calendar__day--muted');
              div.textContent = '';
            }else{
              const iso = year + '-' + String(monthIndex + 1).padStart(2,'0') + '-' + String(dayNumber).padStart(2,'0');
              div.textContent = String(dayNumber);
              let filteredGroups = [];
              let providerOwn = false;
              let providerOther = false;
              if(eventsByDate.has(iso)){
                const info = eventsByDate.get(iso);
                if(activeFilter === 'providers'){
                  (info.events || []).forEach(evt=>{
                    if(!evt){ return; }
                    const evtGroups = Array.isArray(evt.groups) ? evt.groups : [];
                    if(!evtGroups.includes('providers')){ return; }
                    const creatorId = evt.created_by ? parseInt(evt.created_by, 10) || 0 : 0;
                    const isOwn = (creatorId === currentUserId)
                      && !!evt.id
                      && String(evt.id).indexOf('coord_msg_') === 0;
                    if(isOwn){
                      providerOwn = true;
                    }else{
                      providerOther = true;
                    }
                  });
                  if(providerOwn || providerOther){
                    filteredGroups = ['providers'];
                  }
                }else{
                  filteredGroups = (info.groups || []).filter(g => g === activeFilter);
                }
                if(filteredGroups.length){
                  div.classList.add('apf-coord-calendar__day--has-event');
                  const markers = document.createElement('div');
                  markers.className = 'apf-coord-calendar__markers';
                  if(activeFilter === 'providers'){
                    if(providerOther){
                      const marker = document.createElement('span');
                      marker.className = 'apf-coord-calendar__marker apf-coord-calendar__marker--providers';
                      marker.title = 'Avisos do financeiro/FAEPA';
                      markers.appendChild(marker);
                    }
                    if(providerOwn){
                      const marker = document.createElement('span');
                      marker.className = 'apf-coord-calendar__marker apf-coord-calendar__marker--providers-own';
                      marker.title = 'Avisos enviados por você';
                      markers.appendChild(marker);
                    }
                    if(providerOwn && providerOther){
                      div.classList.add('apf-coord-calendar__day--group-mixed');
                    }else if(providerOwn){
                      div.classList.add('apf-coord-calendar__day--group-providers-own');
                    }else{
                      div.classList.add('apf-coord-calendar__day--group-providers');
                    }
                  }else{
                    const hasProviders = filteredGroups.includes('providers');
                    const hasCoordinators = filteredGroups.includes('coordinators');
                    if(hasProviders && hasCoordinators){
                      div.classList.add('apf-coord-calendar__day--group-mixed');
                    }else if(hasCoordinators){
                      div.classList.add('apf-coord-calendar__day--group-coordinators');
                    }else{
                      div.classList.add('apf-coord-calendar__day--group-providers');
                    }
                    filteredGroups.forEach(group=>{
                      const safeGroup = (group === 'coordinators') ? 'coordinators' : 'providers';
                      const marker = document.createElement('span');
                      marker.className = 'apf-coord-calendar__marker apf-coord-calendar__marker--' + safeGroup;
                      marker.title = groupLabels[safeGroup] || '';
                      markers.appendChild(marker);
                    });
                  }
                  div.appendChild(markers);
                  const tooltip = buildTooltip({ groups: filteredGroups, titles: info.titles });
                  if(tooltip){
                    div.title = tooltip;
                  }
                }
              }
              if(composeDate && composeDate === iso){
                div.classList.add('apf-coord-calendar__day--selected');
              }
              if(activeFilter === 'providers'){
                div.classList.add('apf-coord-calendar__day--selectable');
                div.setAttribute('tabindex','0');
                div.setAttribute('role','button');
                div.setAttribute('aria-label', 'Selecionar dia ' + formatDateBr(iso));
                div.addEventListener('click', ()=>{
                  setComposeDate(iso);
                  if(filteredGroups.length){
                    openCoordModal(iso, filteredGroups);
                  }
                });
                div.addEventListener('keydown', (event)=>{
                  if(event.key === 'Enter' || event.key === ' '){
                    event.preventDefault();
                    setComposeDate(iso);
                    if(filteredGroups.length){
                      openCoordModal(iso, filteredGroups);
                    }
                  }
                });
              }else if(filteredGroups.length){
                div.setAttribute('role','button');
                div.setAttribute('tabindex','0');
                div.setAttribute('aria-label', 'Ver avisos de ' + formatDateBr(iso));
                div.addEventListener('click', ()=>{
                  openCoordModal(iso, filteredGroups);
                });
                div.addEventListener('keydown', (event)=>{
                  if(event.key === 'Enter' || event.key === ' '){
                    event.preventDefault();
                    openCoordModal(iso, filteredGroups);
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

        renderCalendar();
      }
    })();
    </script>
    <?php
    return ob_get_clean();
} );
