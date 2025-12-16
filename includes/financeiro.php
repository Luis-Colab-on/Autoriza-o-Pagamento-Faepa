<?php
if ( ! defined('ABSPATH') ) { exit; }

if ( ! function_exists('apf_inbox_table_exists') ) {
    /**
     * Checks if the given table exists in the current database.
     */
    function apf_inbox_table_exists( $table_name ) {
        if ( empty( $table_name ) ) {
            return false;
        }
        global $wpdb;
        $like   = $wpdb->esc_like( $table_name );
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
        return ( $result === $table_name );
    }
}

if ( ! function_exists('apf_inbox_build_director_key') ) {
    /**
     * Generates a normalized key used to match a director+course combination on the client side.
     */
    function apf_inbox_build_director_key( $director, $course ) {
        $director = is_string( $director ) ? strtolower( trim( $director ) ) : '';
        $course   = is_string( $course ) ? strtolower( trim( $course ) ) : '';
        return rawurlencode( $director . '|||' . $course );
    }
}

if ( ! function_exists( 'apf_inbox_normalize_course_value' ) ) {
    /**
     * Normalizes a course string using the shared helper when available.
     */
    function apf_inbox_normalize_course_value( $course ) {
        $course = is_string( $course ) ? trim( (string) $course ) : '';
        if ( '' === $course ) {
            return '';
        }
        if ( function_exists( 'apf_normalize_course_label' ) ) {
            $normalized = apf_normalize_course_label( $course );
            if ( '' !== $normalized ) {
                $course = $normalized;
            }
        }
        return $course;
    }
}

if ( ! function_exists( 'apf_inbox_normalize_director_name' ) ) {
    /**
     * Normalizes a director name for fallback lookups.
     */
    function apf_inbox_normalize_director_name( $name ) {
        $name = is_string( $name ) ? preg_replace( '/\s+/', ' ', trim( $name ) ) : '';
        return strtolower( $name );
    }
}

if ( ! function_exists('apf_inbox_resolve_course_name') ) {
    /**
     * Resolves a human-friendly course name for a given WooCommerce product ID.
     */
    function apf_inbox_resolve_course_name( $product_id ) {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return '';
        }

        $base_id = $product_id;
        $name    = '';

        if ( function_exists('wc_get_product') ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
                    $parent_id = (int) $product->get_parent_id();
                    if ( $parent_id ) {
                        $base_id = $parent_id;
                        $parent  = wc_get_product( $parent_id );
                        if ( $parent ) {
                            $name = $parent->get_name();
                        }
                    }
                }

                if ( '' === $name ) {
                    $name = $product->get_name();
                    $base_id = (int) $product->get_id() ?: $base_id;
                }
            }
        }

        if ( '' === $name ) {
            $name = get_post_field( 'post_title', $base_id );
        }

        if ( '' === $name && $base_id !== $product_id ) {
            $name = get_post_field( 'post_title', $product_id );
        }

        $name = is_string( $name ) ? trim( wp_strip_all_tags( $name ) ) : '';

        if ( $name !== '' && function_exists( 'apf_normalize_course_label' ) ) {
            $normalized = apf_normalize_course_label( $name );
            if ( '' !== $normalized ) {
                $name = $normalized;
            }
        }

        return $name;
    }
}

if ( ! function_exists('apf_inbox_get_available_courses') ) {
    /**
     * Returns a sorted list of course names available in the system.
     */
    function apf_inbox_get_available_courses() {
        static $cache = null;

        if ( null !== $cache ) {
            return $cache;
        }

        global $wpdb;

        $names = array();

        $items_table = $wpdb->prefix . 'processa_pagamentos_asaas_subscriptions_items';
        if ( apf_inbox_table_exists( $items_table ) ) {
            $product_ids = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$items_table} WHERE product_id IS NOT NULL AND product_id <> 0 ORDER BY product_id ASC" );
            if ( is_array( $product_ids ) ) {
                foreach ( $product_ids as $pid ) {
                    $pid   = (int) $pid;
                    $label = apf_inbox_resolve_course_name( $pid );
                    if ( $label !== '' ) {
                        $names[ $label ] = true;
                    }
                }
            }
        }

        if ( empty( $names ) ) {
            $product_ids = array();
            if ( function_exists('wc_get_products') ) {
                $product_ids = wc_get_products( array(
                    'limit'  => -1,
                    'status' => 'publish',
                    'return' => 'ids',
                ) );
            }

            if ( empty( $product_ids ) ) {
                $product_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY post_title ASC",
                        'product',
                        'publish'
                    )
                );
            }

            if ( is_array( $product_ids ) ) {
                foreach ( $product_ids as $pid ) {
                    $pid   = (int) $pid;
                    if ( $pid <= 0 ) {
                        continue;
                    }
                    $label = apf_inbox_resolve_course_name( $pid );
                    if ( $label !== '' ) {
                        $names[ $label ] = true;
                    }
                }
            }
        }

        if ( empty( $names ) ) {
            $meta_values = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value ASC",
                    'apf_nome_curto'
                )
            );
            if ( is_array( $meta_values ) ) {
                foreach ( $meta_values as $value ) {
                    $label = sanitize_text_field( $value );
                    if ( '' === $label ) {
                        continue;
                    }
                    if ( function_exists( 'apf_normalize_course_label' ) ) {
                        $label = apf_normalize_course_label( $label );
                    }
                    if ( '' !== $label ) {
                        $names[ $label ] = true;
                    }
                }
            }
        }

        if ( empty( $names ) ) {
            $cache = array();
            return $cache;
        }

        $labels = array_keys( $names );
        natcasesort( $labels );
        $cache = array_values( $labels );

        return $cache;
    }
}

if ( ! function_exists( 'apf_scheduler_make_recipient_key' ) ) {
    /**
     * Builds a stable identifier for scheduler recipients, allowing per-channel aliases.
     *
     * @param int    $user_id
     * @param string $email
     * @param string $context providers|coordinators
     * @return string
     */
    function apf_scheduler_make_recipient_key( $user_id, $email, $context = '' ) {
        $user_id = (int) $user_id;
        $email   = sanitize_email( $email );
        $context = in_array( $context, array( 'providers', 'coordinators' ), true ) ? $context : '';
        $suffix  = $context ? '_' . $context : '';

        if ( $user_id > 0 ) {
            return 'user_' . $user_id . $suffix;
        }
        if ( $email !== '' ) {
            return 'email_' . strtolower( $email ) . $suffix;
        }
        return '';
    }
}

if ( ! function_exists( 'apf_scheduler_get_events' ) ) {
    /**
     * Returns all scheduled announcements stored in the option table.
     *
     * Each event contains: id, date (Y-m-d), title, recipients (array with key, user_id, name, email), created_by, created_at.
     *
     * @return array<int,array>
     */
    function apf_scheduler_get_events() {
        $events = get_option( 'apf_scheduler_events', array() );
        if ( ! is_array( $events ) ) {
            return array();
        }

        $output = array();
        foreach ( $events as $event ) {
            if ( ! is_array( $event ) ) {
                continue;
            }
            $date = isset( $event['date'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $event['date'] ) : '';
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                continue;
            }
            $title = isset( $event['title'] ) ? sanitize_text_field( $event['title'] ) : '';
            if ( '' === $title ) {
                continue;
            }
            $recipients = array();
            if ( ! empty( $event['recipients'] ) && is_array( $event['recipients'] ) ) {
                foreach ( $event['recipients'] as $recipient ) {
                    $recipient_user = 0;
                    $recipient_email = '';
                    $recipient_name  = '';
                    $recipient_key   = '';

                    if ( is_array( $recipient ) ) {
                        $recipient_user  = isset( $recipient['user_id'] ) ? (int) $recipient['user_id'] : 0;
                        $recipient_email = sanitize_email( $recipient['email'] ?? '' );
                        $recipient_name  = sanitize_text_field( $recipient['name'] ?? '' );
                        $recipient_key   = isset( $recipient['key'] ) ? preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', (string) $recipient['key'] ) : '';
                    } elseif ( is_numeric( $recipient ) ) {
                        // backward compatibility (old format storing only user IDs)
                        $recipient_user = (int) $recipient;
                        if ( $recipient_user > 0 ) {
                            $user = get_user_by( 'id', $recipient_user );
                            if ( $user ) {
                                $recipient_email = sanitize_email( $user->user_email );
                                $recipient_name  = sanitize_text_field( $user->display_name ?: $user->user_login );
                            }
                        }
                    }

                    $recipient_group = '';
                    if ( is_array( $recipient ) && isset( $recipient['group'] ) ) {
                        $recipient_group = sanitize_key( $recipient['group'] );
                    }
                    if ( ! in_array( $recipient_group, array( 'providers', 'coordinators' ), true ) ) {
                        $recipient_group = 'providers';
                    }

                    if ( '' === $recipient_email ) {
                        continue;
                    }
                    if ( '' === $recipient_key ) {
                        $recipient_key = apf_scheduler_make_recipient_key( $recipient_user, $recipient_email, $recipient_group );
                    }
                    if ( '' === $recipient_name ) {
                        $recipient_name = $recipient_email;
                    }

                    $recipient_dir_key  = '';
                    $recipient_course   = '';
                    $recipient_dir_name = '';
                    if ( is_array( $recipient ) ) {
                        $recipient_dir_key = isset( $recipient['director_key'] ) ? sanitize_text_field( $recipient['director_key'] ) : '';
                        $recipient_course  = isset( $recipient['course'] ) ? sanitize_text_field( $recipient['course'] ) : '';
                        $recipient_dir_name = isset( $recipient['director_name'] ) ? sanitize_text_field( $recipient['director_name'] ) : '';
                    }

                    $recipients[] = array(
                        'key'          => $recipient_key,
                        'user_id'      => $recipient_user,
                        'name'         => $recipient_name,
                        'email'        => $recipient_email,
                        'group'        => $recipient_group,
                        'director_key' => $recipient_dir_key,
                        'director_name'=> $recipient_dir_name,
                        'course'       => $recipient_course,
                    );
                }
            }
            if ( empty( $recipients ) ) {
                continue;
            }

            $event_id = isset( $event['id'] ) ? preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', (string) $event['id'] ) : '';
            if ( '' === $event_id ) {
                $event_id = uniqid( 'sched_' );
            }

            $output[] = array(
                'id'          => $event_id,
                'date'        => $date,
                'title'       => $title,
                'recipients'  => $recipients,
                'created_by'  => isset( $event['created_by'] ) ? (int) $event['created_by'] : 0,
                'created_at'  => isset( $event['created_at'] ) ? (int) $event['created_at'] : time(),
            );
        }

        usort( $output, function( $a, $b ) {
            if ( $a['date'] === $b['date'] ) {
                return strcmp( $a['title'], $b['title'] );
            }
            return strcmp( $a['date'], $b['date'] );
        } );

        return $output;
    }
}

if ( ! function_exists( 'apf_scheduler_store_events' ) ) {
    /**
     * Persists the list of scheduled announcements.
     *
     * @param array $events
     */
    function apf_scheduler_store_events( $events ) {
        update_option( 'apf_scheduler_events', $events, false );
    }
}

if ( ! function_exists( 'apf_scheduler_build_recipient_payload' ) ) {
    /**
     * Normalizes the recipient submission into the payload structure stored in events.
     *
     * @param array|string $raw_recipients
     * @param array        $scheduler_provider_index
     * @return array<int,array<string,mixed>>
     */
    function apf_scheduler_build_recipient_payload( $raw_recipients, $scheduler_provider_index ) {
        $recipient_tokens = array();
        if ( is_array( $raw_recipients ) ) {
            foreach ( $raw_recipients as $token ) {
                $token = trim( (string) $token );
                if ( $token !== '' ) {
                    $recipient_tokens[] = $token;
                }
            }
        } elseif ( is_string( $raw_recipients ) && $raw_recipients !== '' ) {
            $chunks = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
            foreach ( $chunks as $chunk ) {
                if ( $chunk !== '' ) {
                    $recipient_tokens[] = $chunk;
                }
            }
        }
        $recipient_tokens = array_values( array_unique( $recipient_tokens ) );

        $recipient_payload = array();
        foreach ( $recipient_tokens as $token ) {
            $raw   = (string) $token;
            $group = 'providers';
            $key   = $raw;
            if ( strpos( $raw, '::' ) !== false ) {
                list( $maybe_group, $maybe_key ) = explode( '::', $raw, 2 );
                $maybe_group = sanitize_key( $maybe_group );
                if ( in_array( $maybe_group, array( 'providers', 'coordinators' ), true ) && $maybe_key !== '' ) {
                    $group = $maybe_group;
                    $key   = preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', $maybe_key );
                }
            }
            if ( '' === $key ) {
                continue;
            }

            $provider = null;
            if ( isset( $scheduler_provider_index[ $group ][ $key ] ) ) {
                $provider = $scheduler_provider_index[ $group ][ $key ];
            } elseif ( isset( $scheduler_provider_index['providers'][ $key ] ) ) {
                $provider = $scheduler_provider_index['providers'][ $key ];
                $group    = $provider['group'] ?? 'providers';
            } elseif ( isset( $scheduler_provider_index['coordinators'][ $key ] ) ) {
                $provider = $scheduler_provider_index['coordinators'][ $key ];
                $group    = 'coordinators';
            }

            if ( empty( $provider ) ) {
                continue;
            }

            $recipient_payload[] = array(
                'key'           => $provider['key'],
                'user_id'       => $provider['user_id'],
                'name'          => $provider['name'],
                'email'         => $provider['email'],
                'group'         => $group,
                'director_key'  => isset( $provider['director_key'] ) ? sanitize_text_field( $provider['director_key'] ) : '',
                'director_name' => isset( $provider['director_name'] ) ? sanitize_text_field( $provider['director_name'] ) : '',
                'course'        => isset( $provider['course'] ) ? sanitize_text_field( $provider['course'] ) : '',
            );
        }

        return $recipient_payload;
    }
}

/* ====== DASHBOARD FINANCEIRO: shortcode [apf_inbox] ====== */
add_shortcode('apf_inbox', function () {

    if ( ! is_user_logged_in() || ! current_user_can('edit_posts') ) {
        return '<p>Faça login com um usuário autorizado para ver as submissões.</p>';
    }

    if ( function_exists( 'apf_get_directors_list' ) ) {
        $directors = apf_get_directors_list();
    } else {
        $directors = get_option('apf_directors', array());
        if ( ! is_array($directors) ) {
            $directors = array();
        }
    }
    $directors = apf_normalize_directors_list( $directors );

    if ( isset($_POST['apf_directors_action']) ) {
        $user_id           = get_current_user_id();
        $transient_key     = $user_id ? 'apf_dir_last_'.$user_id : '';
        $current_signature = '';
        $redirect_notice   = '';
        $redirect_type     = 'success';

        if ( ! isset($_POST['apf_directors_nonce']) || ! wp_verify_nonce($_POST['apf_directors_nonce'], 'apf_directors_manage') ) {
            $redirect_notice = 'Não foi possível processar sua solicitação. Recarregue a página e tente novamente.';
            $redirect_type   = 'error';
        } else {
            $action = sanitize_text_field( wp_unslash( $_POST['apf_directors_action'] ) );

            // normaliza array atual para garantir que seja indexado
            $directors = array_values($directors);

            if ( $action === 'add' ) {
                $course   = sanitize_text_field( wp_unslash( $_POST['apf_dir_course'] ?? '' ) );
                $director = sanitize_text_field( wp_unslash( $_POST['apf_dir_name'] ?? '' ) );
                $email    = sanitize_email( wp_unslash( $_POST['apf_dir_email'] ?? '' ) );

                if ( $course && function_exists( 'apf_normalize_course_label' ) ) {
                    $course = apf_normalize_course_label( $course );
                }

                $current_signature = 'add|' . strtolower($course) . '|' . strtolower($director) . '|' . strtolower($email);

                if ( $course && $director && $email ) {
                    if ( $transient_key && $current_signature === get_transient($transient_key) ) {
                        $redirect_notice = 'Nada foi alterado (a última ação já tinha sido aplicada).';
                    } else {
                        $directors[] = array(
                            'id'       => uniqid('dir_'),
                            'course'   => $course,
                            'director' => $director,
                            'email'    => $email,
                            'status'   => 'approved',
                            'status_updated_at' => time(),
                            'status_updated_by' => $user_id,
                        );
                        update_option('apf_directors', $directors, false);
                        $redirect_notice = 'Coordenador adicionado.';
                        if ( $transient_key && $current_signature ) {
                            set_transient($transient_key, $current_signature, 2 * MINUTE_IN_SECONDS);
                        }
                    }
                } else {
                    $redirect_notice = 'Informe curso, coordenador e e-mail para adicionar.';
                    $redirect_type   = 'error';
                }
            } elseif ( $action === 'update' ) {
                $id       = isset($_POST['apf_dir_id']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', wp_unslash( $_POST['apf_dir_id'] )) : '';
                $course   = sanitize_text_field( wp_unslash( $_POST['apf_dir_course'] ?? '' ) );
                $director = sanitize_text_field( wp_unslash( $_POST['apf_dir_name'] ?? '' ) );
                $email    = sanitize_email( wp_unslash( $_POST['apf_dir_email'] ?? '' ) );
                $updated  = false;
                $already_applied = false;

                if ( $course && function_exists( 'apf_normalize_course_label' ) ) {
                    $course = apf_normalize_course_label( $course );
                }

                $current_signature = 'upd|' . strtolower($id) . '|' . strtolower($course) . '|' . strtolower($director) . '|' . strtolower($email);

                foreach ( $directors as $idx => $item ) {
                    $item_id = isset($item['id']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string) $item['id']) : '';
                    if ( $item_id === $id ) {
                        if ( $course && $director && $email ) {
                            if ( $transient_key && $current_signature === get_transient($transient_key) ) {
                                $redirect_notice = 'Nada foi alterado (a última ação já tinha sido aplicada).';
                                $updated = true;
                                $already_applied = true;
                            } else {
                                $directors[$idx]['course']   = $course;
                                $directors[$idx]['director'] = $director;
                                $directors[$idx]['email']    = $email;
                                $updated = true;
                                if ( $transient_key && $current_signature ) {
                                    set_transient($transient_key, $current_signature, 2 * MINUTE_IN_SECONDS);
                                }
                            }
                        }
                        break;
                    }
                }

                if ( $updated ) {
                    if ( ! $already_applied ) {
                        update_option('apf_directors', $directors, false);
                        $redirect_notice = 'Coordenador atualizado.';
                    } elseif ( empty($redirect_notice) ) {
                        $redirect_notice = 'Coordenador atualizado.';
                    }
                } else {
                    $redirect_notice = 'Não foi possível atualizar o coordenador selecionado.';
                    $redirect_type   = 'error';
                }
            } elseif ( $action === 'delete' ) {
                $id      = isset($_POST['apf_dir_id']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', wp_unslash( $_POST['apf_dir_id'] )) : '';
                $current_signature = 'del|' . strtolower($id);
                $initial = count($directors);
                if ( $transient_key && $current_signature === get_transient($transient_key) ) {
                    $redirect_notice = 'Nada foi alterado (a última ação já tinha sido aplicada).';
                } else {
                    $directors = array_values( array_filter( $directors, function( $item ) use ( $id ) {
                        $item_id = isset($item['id']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string) $item['id']) : '';
                        return $item_id !== $id;
                    }) );

                    if ( count($directors) !== $initial ) {
                        update_option('apf_directors', $directors, false);
                        $redirect_notice = 'Coordenador removido.';
                        if ( $transient_key && $current_signature ) {
                            set_transient($transient_key, $current_signature, 2 * MINUTE_IN_SECONDS);
                        }
                    } else {
                        $redirect_notice = 'Coordenador não encontrado para remoção.';
                        $redirect_type   = 'error';
                    }
                }
            } elseif ( $action === 'approve' || $action === 'reject' ) {
                $id = isset($_POST['apf_dir_id']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', wp_unslash( $_POST['apf_dir_id'] )) : '';
                $target_status = ( 'approve' === $action ) ? 'approved' : 'rejected';
                $updated = false;

                foreach ( $directors as $idx => $item ) {
                    $item_id = isset($item['id']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string) $item['id']) : '';
                    if ( $item_id === $id ) {
                        $directors[$idx]['status'] = $target_status;
                        $directors[$idx]['status_updated_at'] = time();
                        $directors[$idx]['status_updated_by'] = $user_id;
                        if ( 'approved' === $target_status ) {
                            $directors[$idx]['approved_at'] = time();
                            $directors[$idx]['approved_by'] = $user_id;
                            unset( $directors[$idx]['rejected_at'], $directors[$idx]['rejected_by'] );
                        } else {
                            $directors[$idx]['rejected_at'] = time();
                            $directors[$idx]['rejected_by'] = $user_id;
                        }
                        $updated = true;
                        break;
                    }
                }

                if ( $updated ) {
                    update_option('apf_directors', $directors, false);
                    $redirect_notice = ( 'approved' === $target_status )
                        ? 'Coordenador aprovado com sucesso.'
                        : 'Coordenador marcado como recusado.';
                } else {
                    $redirect_notice = 'Não foi possível localizar o coordenador selecionado.';
                    $redirect_type   = 'error';
                }
            } else {
                $redirect_notice = 'Ação inválida.';
                $redirect_type   = 'error';
            }
        }

        if ( '' === $redirect_notice ) {
            $redirect_notice = ( 'error' === $redirect_type )
                ? 'Não foi possível processar a solicitação.'
                : 'Coordenadores atualizados.';
        }
        $redirect_notice = sanitize_text_field( $redirect_notice );
        $redirect_type   = ( 'error' === $redirect_type ) ? 'error' : 'success';

        $target = '';
        if ( ! empty($_SERVER['REQUEST_URI']) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target && function_exists('get_permalink') ) {
            $target = get_permalink();
        }
        if ( '' === $target ) {
            $target = home_url('/');
        }

        $target = remove_query_arg(array('apf_dir_notice','apf_dir_status'), $target);
        if ( strpos($target, 'http') !== 0 && strpos($target, '/') !== 0 ) {
            $target = '/' . ltrim($target, '/');
        }

        $target = add_query_arg(array(
            'apf_dir_notice' => $redirect_notice,
            'apf_dir_status' => $redirect_type,
        ), $target);

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    $director_notice      = '';
    $director_notice_type = 'success';
    if ( isset($_GET['apf_dir_notice']) ) {
        $director_notice = sanitize_text_field( wp_unslash( $_GET['apf_dir_notice'] ) );
        if ( isset($_GET['apf_dir_status']) && 'error' === sanitize_text_field( wp_unslash( $_GET['apf_dir_status'] ) ) ) {
            $director_notice_type = 'error';
        }
    }

    $scheduler_notice      = '';
    $scheduler_notice_type = 'success';
    if ( isset( $_GET['apf_sched_notice'] ) ) {
        $scheduler_notice = sanitize_text_field( wp_unslash( $_GET['apf_sched_notice'] ) );
        if ( isset( $_GET['apf_sched_status'] ) && 'error' === sanitize_text_field( wp_unslash( $_GET['apf_sched_status'] ) ) ) {
            $scheduler_notice_type = 'error';
        }
    }

    $assign_notice      = '';
    $assign_notice_type = 'success';
    if ( isset( $_GET['apf_assign_notice'] ) ) {
        $assign_notice = sanitize_text_field( wp_unslash( $_GET['apf_assign_notice'] ) );
        if ( isset( $_GET['apf_assign_status'] ) && 'error' === sanitize_text_field( wp_unslash( $_GET['apf_assign_status'] ) ) ) {
            $assign_notice_type = 'error';
        }
    }

    // ordena lista por curso/coordenador para exibição consistente
    if ( ! empty($directors) ) {
        usort($directors, function( $a, $b ){
            $course_a = $a['course'] ?? '';
            $course_b = $b['course'] ?? '';
            $by_course = strcasecmp($course_a, $course_b);
            if ( $by_course !== 0 ) {
                return $by_course;
            }
            return strcasecmp($a['director'] ?? '', $b['director'] ?? '');
        });
    }

    // Oculta coordenadores recusados da listagem “Coordenadores por curso”.
    $visible_directors = array_values( array_filter( $directors, function( $entry ) {
        $status        = isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'approved';
        $was_rejected  = ( 'rejected' === $status );
        if ( ! $was_rejected && ! empty( $entry['rejected_at'] ) ) {
            $was_rejected = true;
        }
        return ! $was_rejected;
    } ) );
    $directors_for_ui = $visible_directors;

    $director_filter_choices = array();
    $approved_director_map   = array();
    $approved_director_by_name = array();
    if ( ! empty( $directors_for_ui ) ) {
        foreach ( $directors_for_ui as $entry ) {
            if ( isset( $entry['status'] ) && 'approved' !== $entry['status'] ) {
                continue;
            }
            $director_name = isset( $entry['director'] ) ? trim( (string) $entry['director'] ) : '';
            if ( '' === $director_name ) {
                continue;
            }
            $course_name      = isset( $entry['course'] ) ? trim( (string) $entry['course'] ) : '';
            $course_name_key  = apf_inbox_normalize_course_value( $course_name );
            $key   = apf_inbox_build_director_key( $director_name, $course_name_key );
            if ( '' === $key ) {
                continue;
            }
            $label = ( '' !== $course_name ) ? $director_name . ' — ' . $course_name : $director_name;
            $director_filter_choices[ $key ] = array(
                'label'    => $label,
                'director' => $director_name,
                'course'   => $course_name,
                'id'       => isset( $entry['id'] ) ? (string) $entry['id'] : '',
                'email'    => isset( $entry['email'] ) ? sanitize_email( $entry['email'] ) : '',
                'user_id'  => isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0,
                'course_key' => $course_name_key,
            );
            $entry_with_meta = $entry;
            $entry_with_meta['_apf_dir_key']     = $key;
            $entry_with_meta['_apf_course_key']  = $course_name_key;
            $entry_with_meta['_apf_director_name_key'] = apf_inbox_normalize_director_name( $director_name );
            $approved_director_map[ $key ] = $entry_with_meta;

            $name_key = apf_inbox_normalize_director_name( $director_name );
            if ( '' !== $name_key ) {
                if ( array_key_exists( $name_key, $approved_director_by_name ) ) {
                    $approved_director_by_name[ $name_key ] = null;
                } else {
                    $approved_director_by_name[ $name_key ] = $entry_with_meta;
                }
            }
        }
    }
    if ( ! empty( $director_filter_choices ) ) {
        uasort( $director_filter_choices, function( $a, $b ){
            return strcasecmp( $a['label'], $b['label'] );
        });
    }

    if ( isset( $_POST['apf_assign_action'] ) ) {
        if ( ! isset( $_POST['apf_assign_nonce'] ) || ! wp_verify_nonce( $_POST['apf_assign_nonce'], 'apf_assign_request' ) ) {
            $assign_notice      = 'Não foi possível enviar a solicitação. Recarregue a página e tente novamente.';
            $assign_notice_type = 'error';
        } else {
            $assign_action = sanitize_text_field( wp_unslash( $_POST['apf_assign_action'] ) );
            $target_key    = '';
            if ( isset( $_POST['apf_assign_coordinator'] ) ) {
                // Rebuild the coordinator key to keep it in the same canonical format used when storing entries.
                $raw_value = wp_unslash( $_POST['apf_assign_coordinator'] );
                if ( is_string( $raw_value ) && '' !== $raw_value ) {
                    $decoded_value = rawurldecode( $raw_value );
                    $parts         = explode( '|||', $decoded_value );
                    $director_part = isset( $parts[0] ) ? sanitize_text_field( $parts[0] ) : '';
                    $course_part   = '';
                    if ( count( $parts ) > 1 ) {
                        $course_part = implode( '|||', array_slice( $parts, 1 ) );
                    }
                    $course_part = sanitize_text_field( $course_part );
                    if ( '' !== $director_part || '' !== $course_part ) {
                        $target_key = apf_inbox_build_director_key( $director_part, $course_part );
                    }
                    if ( '' === $target_key ) {
                        $target_key = sanitize_text_field( $raw_value );
                    }
                }
            }
            $rows_raw      = isset( $_POST['apf_assign_rows'] ) ? wp_unslash( $_POST['apf_assign_rows'] ) : '';
            $row_ids       = array();
            $note_title    = isset( $_POST['apf_assign_note_title'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_assign_note_title'] ) ) : '';
            $note_body     = isset( $_POST['apf_assign_note_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['apf_assign_note_body'] ) ) : '';

            if ( '' !== $note_title && function_exists( 'wp_html_excerpt' ) ) {
                $note_title = wp_html_excerpt( $note_title, 160, '' );
            }
            if ( '' !== $note_body && function_exists( 'wp_html_excerpt' ) ) {
                $note_body = wp_html_excerpt( $note_body, 1200, '' );
            }

            if ( '' !== $rows_raw ) {
                $chunks = array_filter( array_map( 'trim', explode( ',', $rows_raw ) ) );
                foreach ( $chunks as $chunk ) {
                    $row_ids[] = (int) preg_replace( '/\D+/', '', $chunk );
                }
            }
            $row_ids = array_values( array_unique( array_filter( $row_ids ) ) );

            $selected_fallback_entry = ( '' !== $target_key && isset( $approved_director_map[ $target_key ] ) ) ? $approved_director_map[ $target_key ] : null;

            if ( 'send' !== $assign_action || '' === $target_key ) {
                $assign_notice      = 'Informe o coordenador e os colaboradores para prosseguir.';
                $assign_notice_type = 'error';
            } elseif ( empty( $row_ids ) ) {
                $assign_notice      = 'Selecione ao menos um colaborador.';
                $assign_notice_type = 'error';
            } elseif ( empty( $selected_fallback_entry ) ) {
                $assign_notice      = 'Coordenador não encontrado ou pendente de aprovação.';
                $assign_notice_type = 'error';
            } else {
                $batch_id     = uniqid( 'batch_' );
                $created = 0;
                $skipped_missing = 0;
                $records = array();
                foreach ( $row_ids as $post_id ) {
                    $post_id = (int) $post_id;
                    if ( $post_id <= 0 || 'apf_submission' !== get_post_type( $post_id ) ) {
                        continue;
                    }

                    $meta_director = get_post_meta( $post_id, 'apf_nome_diretor', true );
                    $meta_course   = get_post_meta( $post_id, 'apf_nome_curto', true );
                    $meta_course_key = apf_inbox_normalize_course_value( $meta_course );
                    $meta_director_key = apf_inbox_normalize_director_name( $meta_director );

                    $final_key   = '';
                    $target_entry = null;

                    if ( $meta_director || $meta_course_key ) {
                        $submission_key = apf_inbox_build_director_key( $meta_director, $meta_course_key );
                        if ( isset( $approved_director_map[ $submission_key ] ) ) {
                            $target_entry = $approved_director_map[ $submission_key ];
                            $final_key    = $target_entry['_apf_dir_key'] ?? $submission_key;
                        }
                    }

                    if ( null === $target_entry && '' !== $meta_director_key && isset( $approved_director_by_name[ $meta_director_key ] ) && $approved_director_by_name[ $meta_director_key ] ) {
                        $target_entry = $approved_director_by_name[ $meta_director_key ];
                        $final_key    = $target_entry['_apf_dir_key'] ?? $final_key;
                    }

                    if ( null === $target_entry && $selected_fallback_entry ) {
                        $target_entry = $selected_fallback_entry;
                        $final_key    = $selected_fallback_entry['_apf_dir_key'] ?? $target_key;
                    }

                    if ( null === $target_entry || '' === $final_key || ! isset( $approved_director_map[ $final_key ] ) ) {
                        $skipped_missing++;
                        continue;
                    }

                    $payload = apf_get_submission_payload( $post_id );
                    if ( empty( $payload ) ) {
                        continue;
                    }

                    $meta_get = function( $meta_key ) use ( $post_id ) {
                        return get_post_meta( $post_id, 'apf_' . $meta_key, true );
                    };
                    $clean_payload = array();
                    foreach ( $payload as $payload_key => $payload_value ) {
                        if ( '_admin_url' === $payload_key ) {
                            $clean_payload['_admin_url'] = esc_url_raw( $payload_value );
                            continue;
                        }
                        $clean_payload[ $payload_key ] = sanitize_text_field( (string) $payload_value );
                    }
                    $raw_director = $meta_get( 'nome_diretor' );
                    $director_display = $raw_director ? sanitize_text_field( $raw_director ) : ( $clean_payload['Coordenador'] ?? '' );
                    $director_display = sanitize_text_field( (string) $director_display );
                    $control_number = sanitize_text_field( (string) $meta_get( 'num_controle' ) );
                    $provider_phone = sanitize_text_field( (string) $meta_get( 'tel_prestador' ) ?: ( $clean_payload['Telefone'] ?? '' ) );
                    $provider_email_meta = $meta_get( 'email_prest' );
                    $provider_email = $provider_email_meta ? sanitize_email( $provider_email_meta ) : sanitize_email( $clean_payload['E-mail'] ?? '' );
                    $doc_fiscal = sanitize_text_field( (string) $meta_get( 'num_doc_fiscal' ) ?: ( $clean_payload['Doc. Fiscal'] ?? '' ) );
                    $raw_value = $meta_get( 'valor' );
                    $value_display = '';
                    if ( '' !== $raw_value ) {
                        $value_display = number_format( (float) $raw_value, 2, ',', '.' );
                    }
                    if ( '' === $value_display && ! empty( $clean_payload['Valor (R$)'] ) ) {
                        $value_display = sanitize_text_field( $clean_payload['Valor (R$)'] );
                    }
                    if ( '' !== $value_display && stripos( $value_display, 'R$' ) === false ) {
                        $value_display = 'R$ ' . $value_display;
                    }
                    $person_type   = strtolower( (string) $meta_get( 'pessoa_tipo' ) );
                    $is_company    = ( 'pj' === $person_type );
                    $person_label  = $is_company ? 'Pessoa Jurídica' : 'Pessoa Física';
                    $company_name  = sanitize_text_field( (string) $meta_get( 'nome_empresa' ) ?: ( $clean_payload['Nome da Empresa'] ?? ( $clean_payload['Empresa (PJ)'] ?? '' ) ) );
                    $collab_name   = $is_company
                        ? sanitize_text_field( (string) $meta_get( 'nome_colaborador' ) ?: ( $clean_payload['Nome do colaborador'] ?? $clean_payload['Nome do Prestador'] ?? $clean_payload['Nome/Empresa'] ?? '' ) )
                        : sanitize_text_field( (string) $meta_get( 'nome_prof' ) ?: ( $clean_payload['Nome do Prestador'] ?? $clean_payload['Nome/Empresa'] ?? '' ) );
                    $person_name   = $is_company ? ( $collab_name ?: $company_name ) : $collab_name;
                    $person_doc    = $is_company
                        ? sanitize_text_field( (string) ( $meta_get( 'cnpj' ) ?: ( $clean_payload['Documento (CPF/CNPJ)'] ?? $clean_payload['CNPJ'] ?? '' ) ) )
                        : sanitize_text_field( (string) ( $meta_get( 'cpf' ) ?: ( $clean_payload['Documento (CPF/CNPJ)'] ?? $clean_payload['CPF'] ?? '' ) ) );

                    $prest_contas = sanitize_text_field( (string) $meta_get( 'prest_contas' ) );
                    $service_date = sanitize_text_field( (string) $meta_get( 'data_prest' ) ?: ( $clean_payload['Data do Serviço'] ?? '' ) );
                    $classification = sanitize_text_field( (string) $meta_get( 'classificacao' ) ?: ( $clean_payload['Classificação'] ?? '' ) );
                    $service_desc = sanitize_textarea_field( (string) $meta_get( 'descricao' ) );
                    $service_hours = sanitize_text_field( (string) $meta_get( 'carga_horaria' ) ?: ( $clean_payload['Carga Horária (CH)'] ?? '' ) );
                    $bank_name    = sanitize_text_field( (string) $meta_get( 'banco' ) );
                    $bank_agency  = sanitize_text_field( (string) $meta_get( 'agencia' ) );
                    $bank_account = sanitize_text_field( (string) $meta_get( 'conta' ) );

                    $payment_snapshot = array(
                        'Tipo do prestador'               => $person_label,
                        'Empresa (PJ)'                    => $is_company ? ( $company_name ?: '—' ) : '',
                        'Nome do colaborador'             => $person_name ?: '—',
                        'Documento (CPF/CNPJ)'            => $person_doc ?: '—',
                        'Nome Completo Diretor Executivo' => $director_display ?: '—',
                        'Número de Controle Secretaria'   => $control_number ?: '—',
                        'Telefone do Prestador'           => $provider_phone ?: '—',
                        'E-mail do Prestador'             => $provider_email ?: '—',
                        'Número do Documento Fiscal'      => $doc_fiscal ?: '—',
                        'Valor (R$)'                      => $value_display ?: ( $clean_payload['Valor (R$)'] ?? '—' ),
                    );
                    $service_snapshot = array(
                        'Prestação de contas'             => $prest_contas ?: '—',
                        'Data de prestação de serviço'    => $service_date ?: '—',
                        'Classificação'                   => $classification ?: '—',
                        'Descrição do serviço ou material'=> $service_desc ?: ( $clean_payload['Descrição'] ?? '' ),
                        'Carga horária do curso'          => $service_hours ?: '—',
                    );
                    $payout_snapshot = array(
                        'Banco'          => $bank_name ?: '—',
                        'Agência'        => $bank_agency ?: '—',
                        'Conta Corrente' => $bank_account ?: '—',
                    );

                    $records[] = array(
                        'id'                   => uniqid( 'req_' ),
                        'batch_id'             => $batch_id,
                        'coordinator_key'      => $final_key,
                        'coordinator_id'       => isset( $target_entry['id'] ) ? (string) $target_entry['id'] : '',
                        'coordinator_name'     => isset( $target_entry['director'] ) ? sanitize_text_field( (string) $target_entry['director'] ) : '',
                        'coordinator_email'    => isset( $target_entry['email'] ) ? sanitize_email( $target_entry['email'] ) : '',
                        'coordinator_user_id'  => isset( $target_entry['user_id'] ) ? (int) $target_entry['user_id'] : 0,
                        'course'               => isset( $target_entry['course'] ) ? sanitize_text_field( (string) $target_entry['course'] ) : '',
                        'submission_id'        => $post_id,
                        'submission_admin_url' => isset( $clean_payload['_admin_url'] ) ? $clean_payload['_admin_url'] : '',
                        'provider_type'        => $clean_payload['Tipo'] ?? '',
                        'provider_name'        => $clean_payload['Nome do colaborador'] ?? ( $clean_payload['Nome/Empresa'] ?? '' ),
                        'provider_company'     => $clean_payload['Empresa (PJ)'] ?? '',
                        'provider_email'       => $clean_payload['E-mail'] ?? '',
                        'provider_phone'       => $clean_payload['Telefone'] ?? '',
                        'provider_value'       => $clean_payload['Valor (R$)'] ?? '',
                        'snapshot_payment'     => $payment_snapshot,
                        'snapshot_service'     => $service_snapshot,
                        'snapshot_payout'      => $payout_snapshot,
                        'note_title'           => $note_title,
                        'note_body'            => $note_body,
                        'status'               => 'pending',
                        'created_at'           => time(),
                        'created_by'           => get_current_user_id(),
                        'updated_at'           => time(),
                        'payload'              => $clean_payload,
                    );
                    $created++;
                }

                if ( $created > 0 ) {
                    apf_add_coordinator_requests( $records );
                    $assign_notice = ( 1 === $created )
                        ? '1 solicitação enviada ao coordenador.'
                        : $created . ' solicitações enviadas ao coordenador.';
                    if ( $skipped_missing > 0 ) {
                        $assign_notice .= ' ' . $skipped_missing . ' colaborador(es) sem coordenador vinculado foram ignorados.';
                    }
                    $assign_notice_type = 'success';
                } else {
                    $assign_notice      = $skipped_missing
                        ? 'Nenhum colaborador disponível: verifique se cada envio possui coordenador definido.'
                        : 'Nenhum colaborador disponível para envio.';
                    $assign_notice_type = 'error';
                }
            }

            $target_url = '';
            if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
                $target_url = wp_unslash( $_SERVER['REQUEST_URI'] );
            }
            if ( '' === $target_url ) {
                $target_url = home_url( '/' );
            }
            $target_url = remove_query_arg( array( 'apf_assign_notice', 'apf_assign_status' ), $target_url );
            $target_url = add_query_arg( array(
                'apf_assign_notice' => $assign_notice,
                'apf_assign_status' => ( 'success' === $assign_notice_type ) ? 'success' : 'error',
            ), $target_url );

            wp_safe_redirect( esc_url_raw( $target_url ) );
            exit;
        }
    }

    if ( isset( $_POST['apf_faepa_forward_action'] ) ) {
        if ( ! isset( $_POST['apf_faepa_nonce'] ) || ! wp_verify_nonce( $_POST['apf_faepa_nonce'], 'apf_faepa_forward' ) ) {
            $faepa_notice      = 'Não foi possível registrar o envio. Recarregue a página e tente novamente.';
            $faepa_notice_type = 'error';
        } else {
            $batch_id = isset( $_POST['apf_faepa_batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apf_faepa_batch_id'] ) ) : '';
            if ( '' === $batch_id ) {
                $faepa_notice      = 'Lote inválido. Abra novamente o retorno e tente de novo.';
                $faepa_notice_type = 'error';
            } else {
                $entries = array();
                $requests = apf_get_coordinator_requests();
                if ( is_array( $requests ) ) {
                    foreach ( $requests as $entry ) {
                        if ( ! is_array( $entry ) || empty( $entry['batch_id'] ) || $entry['batch_id'] !== $batch_id ) {
                            continue;
                        }
                        $entries[] = $entry;
                    }
                }

                if ( empty( $entries ) ) {
                    $faepa_notice      = 'Nenhum retorno encontrado para este lote.';
                    $faepa_notice_type = 'error';
                } else {
                $pending_count   = 0;
                $approved_count  = 0;
                $already_forwarded = true;
                foreach ( $entries as $entry ) {
                    $status = isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'pending';
                    if ( 'pending' === $status ) {
                        $pending_count++;
                    } elseif ( 'approved' === $status ) {
                        $approved_count++;
                    }
                    if ( empty( $entry['faepa_forwarded'] ) ) {
                        $already_forwarded = false;
                    }
                }

                    if ( $pending_count > 0 ) {
                        $faepa_notice      = 'Ainda existem colaboradores pendentes neste retorno. Valide todos antes de enviar para a FAEPA.';
                        $faepa_notice_type = 'error';
                    } elseif ( $approved_count <= 0 ) {
                        $faepa_notice      = 'Nenhum colaborador aprovado neste retorno. Apenas aprovados podem ser enviados à FAEPA.';
                        $faepa_notice_type = 'error';
                    } else {
                        $updated = apf_mark_coordinator_batch_forwarded( $batch_id, array(
                            'user_id' => get_current_user_id(),
                        ) );
                        if ( $updated || $already_forwarded ) {
                            $faepa_notice      = 'Dados confirmados e enviados para a FAEPA.';
                            $faepa_notice_type = 'success';
                        } else {
                            $faepa_notice      = 'Não foi possível atualizar o envio para a FAEPA.';
                            $faepa_notice_type = 'error';
                        }
                    }
                }
            }
        }

        $target_url = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target_url = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target_url ) {
            $target_url = home_url( '/' );
        }
        $target_url = remove_query_arg( array( 'apf_faepa_notice', 'apf_faepa_status' ), $target_url );
        $target_url = add_query_arg( array(
            'apf_faepa_notice' => $faepa_notice,
            'apf_faepa_status' => ( 'success' === $faepa_notice_type ) ? 'success' : 'error',
        ), $target_url );

        wp_safe_redirect( esc_url_raw( $target_url ) );
        exit;
    }

    $faepa_notice      = '';
    $faepa_notice_type = 'success';
    if ( isset( $_GET['apf_faepa_notice'] ) ) {
        $faepa_notice = sanitize_text_field( wp_unslash( $_GET['apf_faepa_notice'] ) );
        if ( isset( $_GET['apf_faepa_status'] ) && 'error' === sanitize_text_field( wp_unslash( $_GET['apf_faepa_status'] ) ) ) {
            $faepa_notice_type = 'error';
        }
    }

    $available_courses = apf_inbox_get_available_courses();
    $course_pool = array();
    if ( is_array( $available_courses ) ) {
        foreach ( $available_courses as $course_label ) {
            $course_label = trim( (string) $course_label );
            if ( $course_label === '' ) {
                continue;
            }
            $course_pool[ $course_label ] = true;
        }
    }

    if ( ! empty( $directors ) ) {
        foreach ( $directors as $entry ) {
            $existing_course = isset( $entry['course'] ) ? trim( (string) $entry['course'] ) : '';
            if ( $existing_course === '' ) {
                continue;
            }
            $course_pool[ $existing_course ] = true;
        }
    }

    $course_choices = array_keys( $course_pool );
    if ( ! empty( $course_choices ) ) {
        natcasesort( $course_choices );
        $course_choices = array_values( $course_choices );
    } else {
        $course_choices = array();
    }
    $course_select_available = ! empty( $course_choices );

    $q = new WP_Query(array(
        'post_type'      => 'apf_submission',
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => 500,
    ));

    $m = function($id,$k){ return get_post_meta($id, 'apf_'.$k, true); };

    $group_map = array();
    if ( $q->have_posts() ) {
        while ( $q->have_posts() ) : $q->the_post();
            $id        = get_the_ID();
            $author_id = (int) get_post_field( 'post_author', $id );
            $email     = sanitize_email( $m($id,'email_prest') );
            $group_key = '';
            if ( $author_id > 0 ) {
                $group_key = 'author_' . $author_id;
            } elseif ( $email ) {
                $group_key = 'email_' . strtolower( $email );
            } else {
                $group_key = 'post_' . $id;
            }

            $tipo    = $m($id,'pessoa_tipo'); // pf/pj
            $tipo_norm = strtolower( (string) $tipo );
            $empresa = $m($id,'nome_empresa');
            $collab  = ($tipo_norm==='pj')
                ? ( $m($id,'nome_colaborador') ?: $m($id,'nome_prof') )
                : $m($id,'nome_prof');
            $nome = $collab ?: $empresa;
            $doc_id = ( 'pj' === $tipo_norm ) ? $m( $id, 'cnpj' ) : $m( $id, 'cpf' );
            $doc_id = $doc_id ? sanitize_text_field( (string) $doc_id ) : '';
            $tel  = $m($id,'tel_prestador');
            $mail = $m($id,'email_prest');

            $valor = $m($id,'valor');
            $valor_fmt = ($valor !== '') ? number_format((float)$valor, 2, ',', '.') : '';

            $docf   = $m($id,'num_doc_fiscal');
            $dserv  = $m($id,'data_prest');
            $class  = $m($id,'classificacao');
            $curso  = $m($id,'nome_curto');
            $ch     = $m($id,'carga_horaria');
            $dir    = $m($id,'nome_diretor');

            $banco_parts = array_filter( array_map( function( $part ) {
                return trim( (string) $part );
            }, array(
                $m( $id, 'banco' ),
                $m( $id, 'agencia' ),
                $m( $id, 'conta' ),
            ) ) );
            $banco  = $banco_parts ? implode( ' / ', $banco_parts ) : '';
            $admin_url = admin_url('post.php?post='.$id.'&action=edit');
            $course_key   = apf_inbox_normalize_course_value( $curso );
            $director_key = ( $dir || $course_key ) ? apf_inbox_build_director_key( $dir, $course_key ) : '';
            $director_label = trim( $dir ?: '' );
            if ( $director_label !== '' && $curso ) {
                $director_label .= ' — ' . $curso;
            } elseif ( '' === $director_label ) {
                $director_label = $curso ?: '';
            }
            $provider_payload = array(
                'Tipo do prestador'       => ( 'pj' === $tipo_norm ) ? 'Pessoa Jurídica' : 'Pessoa Física',
                'Documento (CPF/CNPJ)'    => $doc_id ?: '—',
            );
            $payload = array_merge( $provider_payload, array(
              'Data'                   => get_the_date('Y-m-d H:i'),
              'Tipo'                   => strtoupper($tipo ?: '—'),
              'Nome/Empresa'           => $nome ?: '—',
              'Nome do colaborador'    => $collab ?: ($empresa ?: '—'),
              'Empresa (PJ)'           => ($tipo_norm==='pj') ? ($empresa ?: '—') : '',
              'Telefone'               => $tel ?: '—',
              'E-mail'                 => $mail ?: '—',
              'Valor (R$)'             => $valor_fmt ?: '—',
            'Coordenador'            => $dir ?: '—',
              'Doc. Fiscal'            => $docf ?: '—',
              'Data do Serviço'        => $dserv ?: '—',
              'Classificação'          => $class ?: '—',
              'Curso'                  => $curso ?: '—',
              'Carga Horária (CH)'     => $ch ?: '—',
              'Banco/Agência/Conta'    => $banco ?: '—',
              '_admin_url'             => $admin_url,
            ) );
            $concat = trim( implode(' ', array_values($payload) ) );

            if ( ! isset( $group_map[ $group_key ] ) ) {
                $group_map[ $group_key ] = array(
                    'entries'      => array(),
                    'search_parts' => array(),
                );
            }

            $group_map[ $group_key ]['entries'][] = array(
                'id'             => $id,
                'timestamp'      => strtotime( get_post_field( 'post_date', $id ) ?: 'now' ),
                'payload'        => $payload,
                'search'         => $concat,
                'director_key'   => $director_key,
                'director_label' => $director_label,
                'director_name'  => sanitize_text_field( $dir ?: '' ),
                'author_id'      => $author_id,
                'email'          => $mail,
                'name'           => $nome ?: ( $mail ?: '' ),
                'company'        => $empresa ?: '',
                'collab'         => $collab ?: '',
            );
            $group_map[ $group_key ]['search_parts'][] = $concat;
        endwhile;
        wp_reset_postdata();
    } else {
        wp_reset_postdata();
    }

    $group_rows = array();
    foreach ( $group_map as $group_key => $group ) {
        if ( empty( $group['entries'] ) ) {
            continue;
        }
        usort( $group['entries'], function( $a, $b ){
            if ( $a['timestamp'] === $b['timestamp'] ) {
                return 0;
            }
            return ( $a['timestamp'] > $b['timestamp'] ) ? -1 : 1;
        } );
        $entries = $group['entries'];
        $latest  = $entries[0];
        $history = array();
        $total   = count( $entries );
        foreach ( $entries as $idx => $entry ) {
            $history[] = array(
                'id'         => $entry['id'],
                'timestamp'  => $entry['timestamp'],
                'payload'    => $entry['payload'],
                'order'      => $idx + 1,
                'is_latest'  => ( $idx === 0 ),
                'total'      => $total,
                'author_id'  => isset( $entry['author_id'] ) ? (int) $entry['author_id'] : 0,
                'email'      => isset( $entry['email'] ) ? sanitize_email( $entry['email'] ) : '',
                'name'       => isset( $entry['name'] ) ? sanitize_text_field( $entry['name'] ) : '',
                'company'    => isset( $entry['company'] ) ? sanitize_text_field( $entry['company'] ) : '',
                'collab'     => isset( $entry['collab'] ) ? sanitize_text_field( $entry['collab'] ) : '',
            );
        }
        $group_rows[] = array(
            'latest'        => $latest,
            'history'       => $history,
            'search'        => implode( ' ', $group['search_parts'] ),
            'count'         => $total,
            'director_key'  => $latest['director_key'],
            'director_label'=> $latest['director_label'],
            'director_name' => isset( $latest['director_name'] ) ? sanitize_text_field( $latest['director_name'] ) : '',
            'author_id'     => isset( $latest['author_id'] ) ? (int) $latest['author_id'] : 0,
            'email'         => isset( $latest['email'] ) ? sanitize_email( $latest['email'] ) : '',
            'name'          => isset( $latest['name'] ) ? sanitize_text_field( $latest['name'] ) : '',
            'company'       => isset( $latest['company'] ) ? sanitize_text_field( $latest['company'] ) : '',
            'collab'        => isset( $latest['collab'] ) ? sanitize_text_field( $latest['collab'] ) : '',
            'group_key'     => $group_key,
        );
    }
    usort( $group_rows, function( $a, $b ){
        $a_ts = isset( $a['latest']['timestamp'] ) ? (int) $a['latest']['timestamp'] : 0;
        $b_ts = isset( $b['latest']['timestamp'] ) ? (int) $b['latest']['timestamp'] : 0;
        if ( $a_ts === $b_ts ) {
            return 0;
        }
        return ( $a_ts > $b_ts ) ? -1 : 1;
    } );

    $scheduler_provider_index = array(
        'providers'    => array(),
        'coordinators' => array(),
    );
    $scheduler_provider_groups = array(
        'providers'    => array(),
        'coordinators' => array(),
    );
    foreach ( $group_rows as $bundle ) {
        $latest    = $bundle['latest'];
        $author_id = isset( $bundle['author_id'] ) ? (int) $bundle['author_id'] : 0;
        $email     = isset( $bundle['email'] ) ? sanitize_email( $bundle['email'] ) : '';
        $name      = isset( $bundle['name'] ) ? sanitize_text_field( $bundle['name'] ) : '';
        $company   = isset( $bundle['company'] ) ? sanitize_text_field( $bundle['company'] ) : '';
        $collab    = isset( $bundle['collab'] ) ? sanitize_text_field( $bundle['collab'] ) : '';

        $alias_email = '';
        if ( $author_id > 0 && function_exists( 'apf_get_user_channel_email' ) ) {
            $alias_email = apf_get_user_channel_email( $author_id, 'collab' );
        }
        if ( '' !== $alias_email ) {
            $email = $alias_email;
        } elseif ( '' === $email && $author_id > 0 ) {
            $user = get_user_by( 'id', $author_id );
            if ( $user ) {
                $email = sanitize_email( $user->user_email );
                if ( '' === $name ) {
                    $name = sanitize_text_field( $user->display_name ?: $user->user_login );
                }
            }
        }

        if ( '' === $email ) {
            continue;
        }

        if ( '' === $name ) {
            $payload = isset( $latest['payload'] ) ? $latest['payload'] : array();
            if ( ! empty( $payload['Nome do colaborador'] ) && $payload['Nome do colaborador'] !== '—' ) {
                $name = sanitize_text_field( $payload['Nome do colaborador'] );
            } elseif ( ! empty( $payload['Nome/Empresa'] ) && $payload['Nome/Empresa'] !== '—' ) {
                $name = sanitize_text_field( $payload['Nome/Empresa'] );
            } elseif ( ! empty( $payload['E-mail'] ) && $payload['E-mail'] !== '—' ) {
                $name = sanitize_text_field( $payload['E-mail'] );
            }
        }
        if ( '' === $name ) {
            $name = $email ?: '';
        }

        $key = apf_scheduler_make_recipient_key( $author_id, $email, 'providers' );
        if ( '' === $key || isset( $scheduler_provider_index['providers'][ $key ] ) ) {
            continue;
        }

        $name  = sanitize_text_field( $name );
        $label = $name;
        if ( $email ) {
            $label .= ' — ' . $email;
        }
        $label = sanitize_text_field( $label );

        $latest_payload = isset( $latest['payload'] ) && is_array( $latest['payload'] ) ? $latest['payload'] : array();
        $director_name = isset( $bundle['director_name'] ) ? sanitize_text_field( $bundle['director_name'] ) : '';
        if ( '' === $director_name && isset( $latest_payload['Coordenador'] ) ) {
            $maybe_dir = sanitize_text_field( $latest_payload['Coordenador'] );
            if ( '' !== $maybe_dir && '—' !== $maybe_dir ) {
                $director_name = $maybe_dir;
            }
        }
        $record = array(
            'key'          => $key,
            'user_id'      => $author_id,
            'name'         => $name,
            'email'        => $email,
            'label'        => $label,
            'group'        => 'providers',
            'director_key' => isset( $latest['director_key'] ) ? sanitize_text_field( $latest['director_key'] ) : '',
            'director_name'=> $director_name,
            'course'       => isset( $latest_payload['Curso'] ) ? sanitize_text_field( $latest_payload['Curso'] ) : '',
        );
        $scheduler_provider_index['providers'][ $key ] = $record;
        $scheduler_provider_groups['providers'][] = $record;
    }

    if ( ! empty( $directors_for_ui ) ) {
        foreach ( $directors_for_ui as $entry ) {
            if ( isset( $entry['status'] ) && 'approved' !== $entry['status'] ) {
                continue;
            }
            $course = isset( $entry['course'] ) ? sanitize_text_field( $entry['course'] ) : '';
            $dir_name = isset( $entry['director'] ) ? sanitize_text_field( $entry['director'] ) : '';
            $dir_email = isset( $entry['email'] ) ? sanitize_email( $entry['email'] ) : '';
            $dir_user  = isset( $entry['user_id'] ) ? (int) $entry['user_id'] : 0;

            $coord_alias = '';
            if ( $dir_user > 0 && function_exists( 'apf_get_user_channel_email' ) ) {
                $coord_alias = apf_get_user_channel_email( $dir_user, 'coordinator' );
            }
            if ( '' !== $coord_alias ) {
                $dir_email = $coord_alias;
            } elseif ( '' === $dir_email && $dir_user > 0 ) {
                $user = get_user_by( 'id', $dir_user );
                if ( $user ) {
                    $dir_email = sanitize_email( $user->user_email );
                    if ( '' === $dir_name ) {
                        $dir_name = sanitize_text_field( $user->display_name ?: $user->user_login );
                    }
                }
            }

            if ( '' === $dir_email ) {
                continue;
            }

            if ( '' === $dir_name ) {
                $dir_name = $dir_email;
            }

            $key = apf_scheduler_make_recipient_key( $dir_user, $dir_email, 'coordinators' );
            if ( '' === $key ) {
                continue;
            }

            if ( isset( $scheduler_provider_index['coordinators'][ $key ] ) ) {
                $scheduler_provider_groups['coordinators'][] = $scheduler_provider_index['coordinators'][ $key ];
                continue;
            }

            if ( isset( $scheduler_provider_index['providers'][ $key ] ) ) {
                $existing = $scheduler_provider_index['providers'][ $key ];
                if ( is_array( $existing ) ) {
                    $clone = $existing;
                    $clone['group'] = 'coordinators';
                    $scheduler_provider_groups['coordinators'][] = $clone;
                    $scheduler_provider_index['coordinators'][ $key ] = $clone;
                }
                continue;
            }

            $label = $dir_name;
            if ( $course ) {
                $label .= ' — ' . $course;
                if ( $dir_email ) {
                    $label .= ' — ' . $dir_email;
                }
            } else {
                $label .= ' — ' . $dir_email;
            }
            $label = sanitize_text_field( $label );

            $record = array(
                'key'          => $key,
                'user_id'      => $dir_user,
                'name'         => $dir_name,
                'email'        => $dir_email,
                'label'        => $label,
                'course'       => $course,
                'group'        => 'coordinators',
                'director_key' => apf_inbox_build_director_key( $dir_name, $course ),
            );

            $scheduler_provider_index['coordinators'][ $key ] = $record;
            $scheduler_provider_groups['coordinators'][] = $record;
        }
    }

    foreach ( $scheduler_provider_groups as $group_key => $entries ) {
        if ( empty( $entries ) ) {
            $scheduler_provider_groups[ $group_key ] = array();
            continue;
        }
        usort( $entries, function( $a, $b ){
            return strcasecmp( $a['label'], $b['label'] );
        } );
        $scheduler_provider_groups[ $group_key ] = array_values( $entries );
    }

    $scheduler_providers_attr = esc_attr( wp_json_encode( $scheduler_provider_groups, JSON_UNESCAPED_UNICODE ) );

    $scheduler_events = apf_scheduler_get_events();

    if ( isset( $_POST['apf_scheduler_action'] ) ) {
        $redirect_notice = '';
        $redirect_type   = 'success';

        $nonce_valid = false;
        $nonce_fields = array( 'apf_scheduler_nonce', 'apf_scheduler_nonce_edit', 'apf_scheduler_nonce_delete' );
        foreach ( $nonce_fields as $nonce_field ) {
            if ( isset( $_POST[ $nonce_field ] ) && wp_verify_nonce( wp_unslash( $_POST[ $nonce_field ] ), 'apf_scheduler_manage' ) ) {
                $nonce_valid = true;
                break;
            }
        }

        if ( ! $nonce_valid ) {
            $redirect_notice = 'Não foi possível validar a solicitação. Recarregue a página e tente novamente.';
            $redirect_type   = 'error';
        } else {
            $action = sanitize_text_field( wp_unslash( $_POST['apf_scheduler_action'] ) );

            if ( 'add' === $action ) {
                $date = isset( $_POST['apf_scheduler_date'] )
                    ? preg_replace( '/[^0-9\-]/', '', (string) wp_unslash( $_POST['apf_scheduler_date'] ) )
                    : '';
                $title = isset( $_POST['apf_scheduler_title'] )
                    ? sanitize_text_field( wp_unslash( $_POST['apf_scheduler_title'] ) )
                    : '';
                $title = function_exists( 'mb_substr' ) ? trim( mb_substr( $title, 0, 120 ) ) : trim( substr( $title, 0, 120 ) );

                $raw_recipients = isset( $_POST['apf_scheduler_recipients'] ) ? wp_unslash( $_POST['apf_scheduler_recipients'] ) : array();
                $recipient_payload = apf_scheduler_build_recipient_payload( $raw_recipients, $scheduler_provider_index );

                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                    $redirect_notice = 'Selecione uma data válida para o aviso.';
                    $redirect_type   = 'error';
                } elseif ( '' === $title ) {
                    $redirect_notice = 'Defina um título para o aviso.';
                    $redirect_type   = 'error';
                } elseif ( empty( $recipient_payload ) ) {
                    $redirect_notice = 'Escolha ao menos um prestador.';
                    $redirect_type   = 'error';
                } else {
                    $scheduler_events[] = array(
                        'id'          => uniqid( 'sched_' ),
                        'date'        => $date,
                        'title'       => $title,
                        'recipients'  => $recipient_payload,
                        'created_by'  => get_current_user_id(),
                        'created_at'  => time(),
                    );
                    apf_scheduler_store_events( $scheduler_events );
                    $redirect_notice = 'Aviso adicionado ao calendário.';
                }
            } elseif ( 'update' === $action ) {
                $event_id = isset( $_POST['apf_scheduler_event'] )
                    ? preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', (string) wp_unslash( $_POST['apf_scheduler_event'] ) )
                    : '';
                $title = isset( $_POST['apf_scheduler_title'] )
                    ? sanitize_text_field( wp_unslash( $_POST['apf_scheduler_title'] ) )
                    : '';
                $title = function_exists( 'mb_substr' ) ? trim( mb_substr( $title, 0, 120 ) ) : trim( substr( $title, 0, 120 ) );
                $raw_recipients = isset( $_POST['apf_scheduler_recipients'] ) ? wp_unslash( $_POST['apf_scheduler_recipients'] ) : array();
                $recipient_payload = apf_scheduler_build_recipient_payload( $raw_recipients, $scheduler_provider_index );

                if ( '' === $event_id ) {
                    $redirect_notice = 'Aviso inválido para edição.';
                    $redirect_type   = 'error';
                } elseif ( '' === $title ) {
                    $redirect_notice = 'Defina um título para o aviso.';
                    $redirect_type   = 'error';
                } elseif ( empty( $recipient_payload ) ) {
                    $redirect_notice = 'Escolha ao menos um destinatário.';
                    $redirect_type   = 'error';
                } else {
                    $event_index = null;
                    foreach ( $scheduler_events as $idx => $event ) {
                        $current_id = isset( $event['id'] ) ? sanitize_text_field( $event['id'] ) : '';
                        if ( '' !== $current_id && $current_id === $event_id ) {
                            $event_index = $idx;
                            break;
                        }
                    }

                    if ( null === $event_index ) {
                        $redirect_notice = 'Não foi possível localizar o aviso selecionado.';
                        $redirect_type   = 'error';
                    } else {
                        $scheduler_events[ $event_index ]['title']      = $title;
                        $scheduler_events[ $event_index ]['recipients'] = $recipient_payload;
                        $scheduler_events[ $event_index ]['updated_at'] = time();
                        $scheduler_events[ $event_index ]['updated_by'] = get_current_user_id();
                        apf_scheduler_store_events( $scheduler_events );
                        $redirect_notice = 'Aviso atualizado.';
                    }
                }
            } elseif ( 'delete' === $action ) {
                $event_id = isset( $_POST['apf_scheduler_event'] )
                    ? preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', (string) wp_unslash( $_POST['apf_scheduler_event'] ) )
                    : '';

                if ( '' === $event_id ) {
                    $redirect_notice = 'Aviso inválido para remoção.';
                    $redirect_type   = 'error';
                } else {
                    $initial = count( $scheduler_events );
                    $scheduler_events = array_values( array_filter( $scheduler_events, function( $event ) use ( $event_id ) {
                        return isset( $event['id'] ) ? ( (string) $event['id'] !== $event_id ) : true;
                    } ) );

                    if ( count( $scheduler_events ) < $initial ) {
                        apf_scheduler_store_events( $scheduler_events );
                        $redirect_notice = 'Aviso removido.';
                    } else {
                        $redirect_notice = 'Não foi possível localizar o aviso selecionado.';
                        $redirect_type   = 'error';
                    }
                }
            } else {
                $redirect_notice = 'Ação inválida.';
                $redirect_type   = 'error';
            }
        }

        if ( '' === $redirect_notice ) {
            $redirect_notice = ( 'error' === $redirect_type )
                ? 'Não foi possível atualizar o calendário.'
                : 'Calendário atualizado.';
        }

        $target = '';
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $target = wp_unslash( $_SERVER['REQUEST_URI'] );
        }
        if ( '' === $target && function_exists( 'get_permalink' ) ) {
            $target = get_permalink();
        }
        if ( '' === $target ) {
            $target = home_url( '/' );
        }

        $target = remove_query_arg( array( 'apf_sched_notice', 'apf_sched_status' ), $target );
        if ( strpos( $target, 'http' ) !== 0 && strpos( $target, '/' ) !== 0 ) {
            $target = '/' . ltrim( $target, '/' );
        }

        $target = add_query_arg( array(
            'apf_sched_notice' => sanitize_text_field( $redirect_notice ),
            'apf_sched_status' => ( 'error' === $redirect_type ) ? 'error' : 'success',
        ), $target );

        wp_safe_redirect( esc_url_raw( $target ) );
        exit;
    }

    $scheduler_events_json  = array();

    foreach ( $scheduler_events as $event ) {
        $event_id    = isset( $event['id'] ) ? sanitize_text_field( $event['id'] ) : '';
        $event_date  = isset( $event['date'] ) ? sanitize_text_field( $event['date'] ) : '';
        $event_title = isset( $event['title'] ) ? sanitize_text_field( $event['title'] ) : '';
        $recipient_rows = isset( $event['recipients'] ) && is_array( $event['recipients'] ) ? $event['recipients'] : array();

        $event_group_flags = array(
            'providers'    => false,
            'coordinators' => false,
        );

        $names = array();
        $recipients_for_json = array();
        foreach ( $recipient_rows as $recipient ) {
            $recipient_name  = isset( $recipient['name'] ) ? sanitize_text_field( $recipient['name'] ) : '';
            $recipient_email = isset( $recipient['email'] ) ? sanitize_email( $recipient['email'] ) : '';

            $display = $recipient_name;
            if ( $recipient_email ) {
                $display = $recipient_name
                    ? $recipient_name . ' <' . $recipient_email . '>'
                    : $recipient_email;
            }
            if ( '' !== $display ) {
                $names[] = $display;
            }

            $recipient_group = isset( $recipient['group'] ) ? sanitize_key( $recipient['group'] ) : '';
            if ( ! in_array( $recipient_group, array( 'providers', 'coordinators' ), true ) ) {
                $recipient_group = 'providers';
            }
            $event_group_flags[ $recipient_group ] = true;

            $recipients_for_json[] = array(
                'key'           => isset( $recipient['key'] ) ? sanitize_text_field( $recipient['key'] ) : '',
                'group'         => $recipient_group,
                'name'          => $recipient_name,
                'email'         => $recipient_email,
                'display'       => $display,
                'director_key'  => isset( $recipient['director_key'] ) ? sanitize_text_field( $recipient['director_key'] ) : '',
                'director_name' => isset( $recipient['director_name'] ) ? sanitize_text_field( $recipient['director_name'] ) : '',
                'course'        => isset( $recipient['course'] ) ? sanitize_text_field( $recipient['course'] ) : '',
            );
        }

        $event_groups = array();
        foreach ( $event_group_flags as $group_key => $has_group ) {
            if ( $has_group ) {
                $event_groups[] = $group_key;
            }
        }
        if ( empty( $event_groups ) ) {
            $event_groups[] = 'providers';
        }

        $scheduler_events_json[] = array(
            'id'     => $event_id,
            'date'   => $event_date,
            'title'  => $event_title,
            'groups' => $event_groups,
            'recipients'      => $recipients_for_json,
            'recipients_text' => $names,
        );
    }

    $scheduler_events_attr = esc_attr( wp_json_encode( $scheduler_events_json, JSON_UNESCAPED_UNICODE ) );
    $scheduler_providers_attr = esc_attr( wp_json_encode( $scheduler_provider_groups, JSON_UNESCAPED_UNICODE ) );

    $coordinator_return_groups = array();
    if ( function_exists( 'apf_get_coordinator_requests' ) ) {
        $coordinator_entries = apf_get_coordinator_requests();
        if ( is_array( $coordinator_entries ) ) {
            foreach ( $coordinator_entries as $entry ) {
                if ( empty( $entry['batch_submitted'] ) ) {
                    continue;
                }
                $group_id = '';
                if ( ! empty( $entry['batch_id'] ) ) {
                    $group_id = sanitize_text_field( (string) $entry['batch_id'] );
                }
                if ( '' === $group_id && ! empty( $entry['id'] ) ) {
                    $group_id = sanitize_text_field( (string) $entry['id'] );
                }
                if ( '' === $group_id ) {
                    continue;
                }

                $title   = isset( $entry['note_title'] ) ? sanitize_text_field( $entry['note_title'] ) : '';
                $message = isset( $entry['note_body'] ) ? sanitize_textarea_field( $entry['note_body'] ) : '';
                if ( ! isset( $coordinator_return_groups[ $group_id ] ) ) {
                    $coordinator_return_groups[ $group_id ] = array(
                        'id'           => $group_id,
                        'title'        => ( '' !== $title ) ? $title : 'Retorno do coordenador',
                        'message'      => $message,
                        'submitted_at' => 0,
                        'counts'       => array(
                            'total'    => 0,
                            'approved' => 0,
                            'rejected' => 0,
                            'pending'  => 0,
                        ),
                        'items'        => array(),
                        'coordinator'  => array(
                            'name'   => isset( $entry['coordinator_name'] ) ? sanitize_text_field( (string) $entry['coordinator_name'] ) : '',
                            'email'  => isset( $entry['coordinator_email'] ) ? sanitize_email( $entry['coordinator_email'] ) : '',
                            'course' => isset( $entry['course'] ) ? sanitize_text_field( (string) $entry['course'] ) : '',
                        ),
                        'faepa_forwarded'     => false,
                        'faepa_forwarded_at'  => 0,
                        'faepa_forwarded_by'  => 0,
                        'faepa_forwarded_note'=> '',
                    );
                }

                $status = isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'pending';
                if ( ! in_array( $status, array( 'approved', 'rejected', 'pending' ), true ) ) {
                    $status = 'pending';
                }
                $decision_at = isset( $entry['decision_at'] ) ? (int) $entry['decision_at'] : 0;
                $submitted_at = isset( $entry['batch_submitted_at'] ) ? (int) $entry['batch_submitted_at'] : 0;
                $updated_at   = isset( $entry['updated_at'] ) ? (int) $entry['updated_at'] : 0;
                $reference_ts = max( $submitted_at, $decision_at, $updated_at );
                $faepa_forwarded = ! empty( $entry['faepa_forwarded'] );
                $faepa_forwarded_at = isset( $entry['faepa_forwarded_at'] ) ? (int) $entry['faepa_forwarded_at'] : 0;
                $faepa_forwarded_by = isset( $entry['faepa_forwarded_by'] ) ? (int) $entry['faepa_forwarded_by'] : 0;
                $faepa_forwarded_note = isset( $entry['faepa_forwarded_note'] ) ? sanitize_textarea_field( $entry['faepa_forwarded_note'] ) : '';

                $coordinator_return_groups[ $group_id ]['counts']['total']++;
                if ( isset( $coordinator_return_groups[ $group_id ]['counts'][ $status ] ) ) {
                    $coordinator_return_groups[ $group_id ]['counts'][ $status ]++;
                }
                if ( $reference_ts > $coordinator_return_groups[ $group_id ]['submitted_at'] ) {
                    $coordinator_return_groups[ $group_id ]['submitted_at'] = $reference_ts;
                }
                if ( $faepa_forwarded ) {
                    $coordinator_return_groups[ $group_id ]['faepa_forwarded'] = true;
                    $coordinator_return_groups[ $group_id ]['faepa_forwarded_at'] = max( $coordinator_return_groups[ $group_id ]['faepa_forwarded_at'], $faepa_forwarded_at );
                    if ( $faepa_forwarded_by > 0 ) {
                        $coordinator_return_groups[ $group_id ]['faepa_forwarded_by'] = $faepa_forwarded_by;
                    }
                    if ( '' === $coordinator_return_groups[ $group_id ]['faepa_forwarded_note'] && '' !== $faepa_forwarded_note ) {
                        $coordinator_return_groups[ $group_id ]['faepa_forwarded_note'] = $faepa_forwarded_note;
                    }
                }

                if ( '' === $coordinator_return_groups[ $group_id ]['coordinator']['name'] && ! empty( $entry['coordinator_name'] ) ) {
                    $coordinator_return_groups[ $group_id ]['coordinator']['name'] = sanitize_text_field( (string) $entry['coordinator_name'] );
                }
                if ( '' === $coordinator_return_groups[ $group_id ]['coordinator']['course'] && ! empty( $entry['course'] ) ) {
                    $coordinator_return_groups[ $group_id ]['coordinator']['course'] = sanitize_text_field( (string) $entry['course'] );
                }
                if ( '' === $coordinator_return_groups[ $group_id ]['coordinator']['email'] && ! empty( $entry['coordinator_email'] ) ) {
                    $coordinator_return_groups[ $group_id ]['coordinator']['email'] = sanitize_email( $entry['coordinator_email'] );
                }

                $details = function_exists( 'apf_coord_build_request_details' )
                    ? apf_coord_build_request_details( $entry )
                    : array();

                $status_label = ( 'approved' === $status )
                    ? 'Aprovado'
                    : ( 'rejected' === $status ? 'Recusado' : 'Pendente' );

                $coordinator_return_groups[ $group_id ]['items'][] = array(
                    'id'             => isset( $entry['id'] ) ? sanitize_text_field( (string) $entry['id'] ) : '',
                    'name'           => isset( $entry['provider_name'] ) ? sanitize_text_field( (string) $entry['provider_name'] ) : '',
                    'value'          => isset( $entry['provider_value'] ) ? sanitize_text_field( (string) $entry['provider_value'] ) : '',
                    'status'         => $status,
                    'status_label'   => $status_label,
                    'decision_at'    => $decision_at,
                    'decision_label' => $decision_at ? date_i18n( 'd/m/Y H:i', $decision_at ) : '',
                    'note'           => isset( $entry['decision_note'] ) ? sanitize_textarea_field( $entry['decision_note'] ) : '',
                    'details'        => $details,
                    'faepa_paid'     => ! empty( $entry['faepa_paid'] ),
                    'faepa_paid_at'  => isset( $entry['faepa_paid_at'] ) ? (int) $entry['faepa_paid_at'] : 0,
                    'faepa_paid_label'=> ( ! empty( $entry['faepa_paid_at'] ) ) ? date_i18n( 'd/m/Y H:i', (int) $entry['faepa_paid_at'] ) : '',
                    'faepa_payment_note' => isset( $entry['faepa_payment_note'] ) ? sanitize_textarea_field( $entry['faepa_payment_note'] ) : '',
                    'faepa_payment_attachment' => isset( $entry['faepa_payment_attachment'] ) ? esc_url_raw( $entry['faepa_payment_attachment'] ) : '',
                    'faepa_payment_attachment_id' => isset( $entry['faepa_payment_attachment_id'] ) ? (int) $entry['faepa_payment_attachment_id'] : 0,
                );
            }
        }
    }

    if ( ! empty( $coordinator_return_groups ) ) {
        foreach ( $coordinator_return_groups as $group_id => $bundle ) {
            if ( empty( $bundle['submitted_at'] ) ) {
                $first_item = isset( $bundle['items'][0]['decision_at'] ) ? (int) $bundle['items'][0]['decision_at'] : 0;
                $coordinator_return_groups[ $group_id ]['submitted_at'] = $first_item;
            }
            $coordinator_return_groups[ $group_id ]['submitted_label'] = ! empty( $coordinator_return_groups[ $group_id ]['submitted_at'] )
                ? date_i18n( 'd/m/Y H:i', (int) $coordinator_return_groups[ $group_id ]['submitted_at'] )
                : '';
            $coordinator_return_groups[ $group_id ]['faepa_forwarded_label'] = ( ! empty( $bundle['faepa_forwarded'] ) && ! empty( $bundle['faepa_forwarded_at'] ) )
                ? date_i18n( 'd/m/Y H:i', (int) $bundle['faepa_forwarded_at'] )
                : '';
        }
        $coordinator_return_groups = array_values( $coordinator_return_groups );
        usort( $coordinator_return_groups, function( $a, $b ){
            $a_ts = isset( $a['submitted_at'] ) ? (int) $a['submitted_at'] : 0;
            $b_ts = isset( $b['submitted_at'] ) ? (int) $b['submitted_at'] : 0;
            if ( $a_ts === $b_ts ) {
                return 0;
            }
            return ( $a_ts > $b_ts ) ? -1 : 1;
        } );
    }

    $coord_return_months = array();
    if ( ! empty( $coordinator_return_groups ) ) {
        foreach ( $coordinator_return_groups as $group_bundle ) {
            if ( empty( $group_bundle['submitted_at'] ) ) {
                continue;
            }
            $month_key = date_i18n( 'Y-m', (int) $group_bundle['submitted_at'] );
            $coord_return_months[ $month_key ] = date_i18n( 'm/Y', (int) $group_bundle['submitted_at'] );
        }
        if ( ! empty( $coord_return_months ) ) {
            krsort( $coord_return_months );
        }
    }

    $visible_return_groups  = $coordinator_return_groups;
    $archived_return_groups = array();
    $archived_return_ids    = array();
    $max_visible_return_cards = count( $coordinator_return_groups );

    $coord_archive_icon_url = plugins_url( 'imgs/box-archive-branca.svg', dirname( __DIR__ ) . '/fomulario_pagamento_faepa.php' );



    ob_start(); ?>
    <div class="apf-inbox-theme">
      <div class="apf-inbox-wrap" id="apfInboxWrap" aria-live="polite">
      <!-- Gestão de Coordenadores -->
      <section class="apf-directors" aria-labelledby="apfDirectorsTitle">
        <div class="apf-directors__head">
          <div>
            <h2 id="apfDirectorsTitle">Coordenadores por curso</h2>
            <p>Cadastre os coordenadores fixos que ficarão disponíveis no formulário público.</p>
          </div>
          <div class="apf-directors__head-actions">
            <button type="button" class="apf-btn apf-director-list-btn" id="apfDirectorListBtn" aria-label="Ver coordenadores">
              <img src="<?php echo esc_url( $coord_archive_icon_url ); ?>" alt="" class="apf-director-list-btn__icon">
            </button>
            <button type="button" class="apf-btn apf-btn--outline-white" id="apfDirectorAddBtn">Adicionar coordenador</button>
          </div>
        </div>

        <?php if ( $director_notice ) : ?>
          <div class="apf-directors__notice apf-directors__notice--<?php echo esc_attr($director_notice_type); ?>">
            <?php echo esc_html($director_notice); ?>
          </div>
        <?php endif; ?>

        <div class="apf-assign-modal apf-director-modal" id="apfDirectorModal" aria-hidden="true">
          <div class="apf-assign-modal__overlay" data-director-modal-close></div>
          <div class="apf-assign-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfDirectorModalTitle">
            <div class="apf-assign-modal__head">
              <h3 id="apfDirectorModalTitle">Adicionar coordenador</h3>
              <button type="button" class="apf-assign-modal__close" data-director-modal-close aria-label="Fechar">&times;</button>
            </div>
            <form method="post" class="apf-directors__form">
              <?php wp_nonce_field('apf_directors_manage','apf_directors_nonce'); ?>
              <input type="hidden" name="apf_directors_action" value="add">
              <div class="apf-assign-modal__body">
                <div class="apf-directors__grid">
                  <label>Curso
                    <?php if ( $course_select_available ) : ?>
                      <select name="apf_dir_course" required>
                        <option value="" selected><?php echo esc_html('Selecione um curso'); ?></option>
                        <?php foreach ( $course_choices as $course_label ) : ?>
                          <option value="<?php echo esc_attr( $course_label ); ?>" title="<?php echo esc_attr( $course_label ); ?>"><?php echo esc_html( $course_label ); ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else : ?>
                      <input type="text" name="apf_dir_course" required placeholder="Ex.: Curso de Atualização em XYZ">
                    <?php endif; ?>
                  </label>
                  <label>Coordenador
                    <input type="text" name="apf_dir_name" required placeholder="Nome completo do coordenador">
                  </label>
                  <label>E-mail
                    <input type="email" name="apf_dir_email" required placeholder="coordenador@exemplo.com">
                  </label>
                </div>
              </div>
              <div class="apf-assign-modal__footer">
                <button type="submit" class="apf-btn apf-btn--outline-white">Salvar</button>
                <button type="button" class="apf-btn apf-btn--white-dark" data-director-modal-close>Cancelar</button>
              </div>
            </form>
          </div>
        </div>

        <?php if ( ! empty( $visible_directors ) ) : ?>
          <div class="apf-assign-modal apf-director-list-modal" id="apfDirectorListModal" aria-hidden="true">
            <div class="apf-assign-modal__overlay" data-director-list-close></div>
            <div class="apf-assign-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfDirectorListTitle">
              <div class="apf-assign-modal__head">
                <div>
                  <h3 id="apfDirectorListTitle">Coordenadores cadastrados</h3>
                  <p class="apf-director-list__hint">Clique em editar para ajustar dados ou excluir para remover.</p>
                </div>
                <button type="button" class="apf-assign-modal__close" data-director-list-close aria-label="Fechar">&times;</button>
              </div>
              <div class="apf-assign-modal__body">
                <label class="apf-director-list__search">
                  <span>Campo de busca</span>
                  <input type="search" id="apfDirectorListSearch" placeholder="Digite nome, e-mail ou curso do coordenador">
                </label>
                <div class="apf-director-list__body" id="apfDirectorListBody">
                <?php foreach ( $visible_directors as $entry ) :
                    $entry_status = isset( $entry['status'] ) ? $entry['status'] : 'approved';
                    $status_label = 'Aprovado';
                    $status_class = 'approved';
                    if ( 'pending' === $entry_status ) {
                        $status_label = 'Aguardando aprovação';
                        $status_class = 'pending';
                    } elseif ( 'rejected' === $entry_status ) {
                        $status_label = 'Recusado';
                        $status_class = 'rejected';
                    }
                    $current_course = isset( $entry['course'] ) ? (string) $entry['course'] : '';
                ?>
                  <form method="post" class="apf-director-card" data-director-form>
                    <?php wp_nonce_field('apf_directors_manage','apf_directors_nonce'); ?>
                    <input type="hidden" name="apf_directors_action" value="update">
                    <input type="hidden" name="apf_dir_id" value="<?php echo esc_attr($entry['id'] ?? ''); ?>">
                    <div class="apf-director-card__fields">
                      <div class="apf-director-card__field apf-director-card__field--full">
                        <span>Curso</span>
                        <?php if ( $course_select_available ) : ?>
                          <select name="apf_dir_course" required disabled data-initial="<?php echo esc_attr( $current_course ); ?>">
                            <option value="" <?php selected( $current_course, '' ); ?>><?php echo esc_html('Selecione um curso'); ?></option>
                            <?php foreach ( $course_choices as $course_label ) : ?>
                              <option value="<?php echo esc_attr( $course_label ); ?>" title="<?php echo esc_attr( $course_label ); ?>" <?php selected( $current_course, $course_label ); ?>><?php echo esc_html( $course_label ); ?></option>
                            <?php endforeach; ?>
                          </select>
                        <?php else : ?>
                          <input type="text" name="apf_dir_course" value="<?php echo esc_attr($entry['course'] ?? ''); ?>" required disabled data-initial="<?php echo esc_attr( $entry['course'] ?? '' ); ?>">
                        <?php endif; ?>
                      </div>
                      <div class="apf-director-card__field">
                        <span>Coordenador</span>
                        <input type="text" name="apf_dir_name" value="<?php echo esc_attr($entry['director'] ?? ''); ?>" required disabled data-initial="<?php echo esc_attr( $entry['director'] ?? '' ); ?>">
                      </div>
                      <div class="apf-director-card__field">
                        <span>E-mail</span>
                        <input type="email" name="apf_dir_email" value="<?php echo esc_attr( $entry['email'] ?? '' ); ?>" required disabled data-initial="<?php echo esc_attr( $entry['email'] ?? '' ); ?>">
                      </div>
                    </div>
                    <div class="apf-director-card__footer">
                      <?php if ( 'approved' !== $status_class ) : ?>
                        <span class="apf-directors__status apf-directors__status--<?php echo esc_attr( $status_class ); ?>">
                          <?php echo esc_html( $status_label ); ?>
                        </span>
                      <?php endif; ?>
                      <div class="apf-director-card__actions">
                        <button type="button" class="apf-btn apf-btn--primary" data-director-edit>Editar</button>
                        <button type="submit" name="apf_directors_action" value="delete" class="apf-btn apf-btn--danger" data-director-delete onclick="return confirm('Remover este coordenador?');">Excluir</button>
                        <button type="submit" class="apf-btn apf-btn--outline-white" data-director-save hidden>Salvar</button>
                        <button type="button" class="apf-btn apf-btn--white-dark" data-director-cancel hidden>Cancelar</button>
                      </div>
                    </div>
                  </form>
                <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </section>

      <section class="apf-scheduler" aria-labelledby="apfSchedulerHeading">
          <div class="apf-scheduler__head">
            <div>
              <h2 id="apfSchedulerHeading">Agenda financeira</h2>
              <p>Programe avisos para coordenadores e colaboradores diretamente no calendário.</p>
            </div>
          </div>

          <div class="apf-scheduler__grid">
            <div class="apf-scheduler__calendar">
              <div id="apfScheduler"
                   data-events="<?php echo $scheduler_events_attr; ?>"
                   data-providers="<?php echo $scheduler_providers_attr; ?>">
                <div class="apf-scheduler__calendar-loading">Carregando calendário…</div>
              </div>
            </div>
            <form method="post" class="apf-scheduler__form" id="apfSchedulerForm">
              <?php wp_nonce_field( 'apf_scheduler_manage', 'apf_scheduler_nonce' ); ?>
              <input type="hidden" name="apf_scheduler_action" value="add">
              <input type="hidden" name="apf_scheduler_date" id="apfSchedulerDate" value="">
              <div id="apfSchedulerRecipientsHolder" aria-hidden="true"></div>
              <div class="apf-scheduler__selected">
                <span class="apf-scheduler__selected-label">Dia selecionado:</span>
                <strong id="apfSchedulerSelectedDisplay">Nenhum dia selecionado</strong>
              </div>
              <div class="apf-scheduler__audience">
                <span>Enviar para:</span>
                <div class="apf-scheduler__tabs" role="tablist">
                  <button type="button" class="apf-scheduler__tab is-active" data-scheduler-group="providers">Colaboradores</button>
                  <button type="button" class="apf-scheduler__tab" data-scheduler-group="coordinators">Coordenadores</button>
                </div>
              </div>
              <label class="apf-scheduler__field">
                <span>Título do aviso</span>
                <input type="text" name="apf_scheduler_title" id="apfSchedulerTitleInput" maxlength="120" placeholder="Ex.: Atualizar dados cadastrais" required>
              </label>
              <label class="apf-scheduler__field">
                <span id="apfSchedulerPickerLabel">Buscar colaboradores</span>
                <input type="search" id="apfSchedulerSearch" placeholder="Digite nome ou e-mail">
              </label>
              <div class="apf-scheduler__providers">
                <p class="apf-scheduler__providers-empty">Nenhum destinatário encontrado.</p>
              </div>
              <div class="apf-scheduler__actions">
                <button type="submit" class="apf-btn apf-btn--primary">Agendar aviso</button>
              </div>
            </form>
          </div>
        </section>

        <div class="apf-scheduler-modal" id="apfSchedulerModal" aria-hidden="true">
          <div class="apf-scheduler-modal__overlay" data-modal-close></div>
          <div class="apf-scheduler-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfSchedulerModalTitle">
            <div class="apf-scheduler-modal__head">
              <h3 id="apfSchedulerModalTitle">Avisos do dia</h3>
              <div class="apf-scheduler-modal__head-actions">
                <button type="button" class="apf-btn apf-btn--primary apf-scheduler-modal__new" data-modal-new>Adicionar novo aviso</button>
                <button type="button" class="apf-scheduler-modal__close" data-modal-close aria-label="Fechar">&times;</button>
              </div>
            </div>
            <div class="apf-scheduler-modal__content">
              <div class="apf-scheduler-modal__list" data-view="list">
                <p class="apf-scheduler-modal__empty">Nenhum aviso programado para esta data.</p>
                <div class="apf-scheduler-modal__items"></div>
              </div>
              <div class="apf-scheduler-modal__edit" data-view="edit" hidden>
                <form method="post" id="apfSchedulerEditForm" class="apf-scheduler-edit">
                  <?php wp_nonce_field( 'apf_scheduler_manage', 'apf_scheduler_nonce_edit' ); ?>
                  <input type="hidden" name="apf_scheduler_action" value="update">
                  <input type="hidden" name="apf_scheduler_event" value="">
                  <input type="hidden" name="apf_scheduler_date" value="">
                  <div class="apf-scheduler-edit__section">
                    <span class="apf-scheduler-edit__date-label">Data selecionada</span>
                    <strong style="color:black;" id="apfSchedulerEditDate">—</strong>
                  </div>
                  <label class="apf-scheduler-edit__field">
                    <span>Título do aviso</span>
                    <input type="text" name="apf_scheduler_title" id="apfSchedulerEditTitle" maxlength="120" required>
                  </label>
                  <div class="apf-scheduler-edit__chips" id="apfSchedulerEditChips">
                    <p class="apf-scheduler-edit__chips-empty">Nenhum destinatário selecionado.</p>
                  </div>
                  <div class="apf-scheduler-edit__section">
                    <div class="apf-scheduler-edit__audience">
                      <span style="color:black;">Adicionar destinatários</span>
                      <div class="apf-scheduler-edit__tabs" role="tablist">
                        <button type="button" class="apf-scheduler-edit__tab is-active" data-edit-group="providers">Colaboradores</button>
                        <button type="button" class="apf-scheduler-edit__tab" data-edit-group="coordinators">Coordenadores</button>
                      </div>
                    </div>
                    <label class="apf-scheduler-edit__field">
                      <span>Buscar</span>
                      <input type="search" id="apfSchedulerEditSearch" placeholder="Digite nome ou e-mail">
                    </label>
                    <div class="apf-scheduler-edit__providers"></div>
                  </div>
                  <div id="apfSchedulerEditRecipientsHolder" aria-hidden="true"></div>
                  <div class="apf-scheduler-edit__footer">
                    <button type="button" class="apf-btn apf-btn--ghost" data-edit-cancel>Cancelar</button>
                    <button type="submit" class="apf-btn apf-btn--primary">Salvar alterações</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
        <form method="post" id="apfSchedulerDeleteForm" class="apf-scheduler-hidden-form">
          <?php wp_nonce_field( 'apf_scheduler_manage', 'apf_scheduler_nonce_delete' ); ?>
          <input type="hidden" name="apf_scheduler_action" value="delete">
          <input type="hidden" name="apf_scheduler_event" value="">
        </form>

      <?php if ( $assign_notice ) : ?>
        <div class="apf-notice apf-notice--<?php echo esc_attr( $assign_notice_type ); ?>">
          <?php echo esc_html( $assign_notice ); ?>
        </div>
      <?php endif; ?>

      <form method="post" id="apfAssignForm" class="apf-assign-form" aria-hidden="true">
        <?php wp_nonce_field( 'apf_assign_request', 'apf_assign_nonce' ); ?>
        <input type="hidden" name="apf_assign_action" value="send">
        <input type="hidden" name="apf_assign_coordinator" id="apfAssignFieldCoordinator" value="">
        <input type="hidden" name="apf_assign_rows" id="apfAssignFieldRows" value="">
        <input type="hidden" name="apf_assign_note_title" id="apfAssignFieldNoteTitle" value="">
        <input type="hidden" name="apf_assign_note_body" id="apfAssignFieldNoteBody" value="">
      </form>

      <div class="apf-assign-panel" id="apfAssignPanel" aria-hidden="true">
        <div class="apf-assign-panel__info">
          <strong>Envio ao coordenador</strong>
          <p id="apfAssignHelper">Escolha um coordenador para liberar a seleção de colaboradores.</p>
        </div>
        <div class="apf-assign-panel__actions">
          <span class="apf-assign-panel__count" id="apfAssignCount">0 selecionados</span>
          <button type="button" class="apf-btn apf-btn--primary" id="apfAssignSend" disabled>Enviar</button>
          <button type="button" class="apf-btn apf-btn--ghost" id="apfAssignCancel">Cancelar</button>
        </div>
      </div>

      <?php if ( ! empty( $director_filter_choices ) ) : ?>
        <div class="apf-assign-modal" id="apfAssignModal" aria-hidden="true">
          <div class="apf-assign-modal__overlay" data-assign-close></div>
          <div class="apf-assign-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfAssignModalTitle">
            <div class="apf-assign-modal__head">
              <h3 id="apfAssignModalTitle">Enviar solicitação</h3>
              <button type="button" class="apf-assign-modal__close" data-assign-close aria-label="Fechar">&times;</button>
            </div>
            <div class="apf-assign-modal__body">
              <p>Escolha o coordenador/curso que receberá os colaboradores selecionados.</p>
              <label>
                <span>Coordenador/Curso</span>
                <select id="apfAssignModalSelect">
                  <option value=""><?php echo esc_html( 'Selecione um coordenador' ); ?></option>
                  <?php foreach ( $director_filter_choices as $option_key => $option_data ) : ?>
                    <option value="<?php echo esc_attr( $option_key ); ?>">
                      <?php echo esc_html( $option_data['label'] ); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div class="apf-assign-modal__footer">
              <button type="button" class="apf-btn apf-btn--ghost" id="apfAssignModalCancel" data-assign-close>Cancelar</button>
              <button type="button" class="apf-btn apf-btn--primary" id="apfAssignModalConfirm">Selecionar</button>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="apf-assign-modal apf-assign-modal--note" id="apfAssignNoteModal" aria-hidden="true">
        <div class="apf-assign-modal__overlay" data-note-close></div>
        <div class="apf-assign-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfAssignNoteTitle">
          <div class="apf-assign-modal__head">
            <h3 id="apfAssignNoteTitle">Mensagem ao coordenador</h3>
            <button type="button" class="apf-assign-modal__close" data-note-close aria-label="Fechar">&times;</button>
          </div>
          <div class="apf-assign-modal__body">
            <p>Inclua um título e um texto opcional para contextualizar os colaboradores enviados.</p>
            <label class="apf-assign-note__field">
              <span>Título do aviso</span>
              <input type="text" id="apfAssignNoteTitleInput" maxlength="160" placeholder="Ex.: Revisar solicitações pendentes">
            </label>
            <label class="apf-assign-note__field">
              <span>Mensagem (opcional)</span>
              <textarea id="apfAssignNoteBodyInput" rows="4" maxlength="1200" placeholder="Detalhe prazos, etapas ou anexos relevantes."></textarea>
            </label>
            <p class="apf-assign-note__hint">Este conteúdo aparece para o coordenador antes de visualizar cada colaborador.</p>
          </div>
          <div class="apf-assign-modal__footer">
            <button type="button" class="apf-btn apf-btn--ghost" data-note-cancel>Voltar</button>
            <button type="button" class="apf-btn apf-btn--primary" id="apfAssignNoteConfirm">Enviar solicitações</button>
          </div>
        </div>
      </div>

      <!-- Toolbar / Busca -->
      <div class="apf-toolbar">
        <form class="apf-search" role="search" aria-label="Busca no dashboard" onsubmit="return false;">
          <div class="apf-search-field">
            <input id="apfQuery" type="search" inputmode="search" autocomplete="off"
                   placeholder="Pesquisar por nome, CPF, CNPJ, telefone, e-mail, nº controle, doc fiscal..." aria-label="Pesquisar" />
          </div>
          <?php if ( ! empty( $director_filter_choices ) ) : ?>
            <div class="apf-filter apf-filter--assign">
              <label for="apfDirectorFilter">
                <span>Coordenador/Curso</span>
              </label>
              <div class="apf-filter__row">
                <select id="apfDirectorFilter">
                  <option value=""><?php echo esc_html( 'Todos os coordenadores' ); ?></option>
                  <?php foreach ( $director_filter_choices as $option_key => $option_data ) : ?>
                    <option value="<?php echo esc_attr( $option_key ); ?>">
                      <?php echo esc_html( $option_data['label'] ); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endif; ?>
          <div class="apf-search-actions">
            <button id="apfBtnSearch" type="button" class="apf-btn apf-btn--primary">Buscar</button>
            <button id="apfBtnClear"  type="button" class="apf-btn apf-btn--ghost">Limpar</button>
          </div>
        </form>
      </div>

      <div class="apf-table-meta">
        <?php if ( ! empty( $director_filter_choices ) ) : ?>
          <button type="button" class="apf-btn apf-btn--ghost apf-assign-inline-desktop" id="apfAssignStart">
            Enviar solicitação ao coordenador
          </button>
        <?php endif; ?>
        <div class="apf-count"><span id="apfCount" class="apf-badge"></span></div>
      </div>

      <div class="apf-table-scroller">
        <table id="apfTable" class="apf-table" aria-describedby="apfCount">
          <thead>
            <tr class="apf-row-pager">
              <th scope="col" colspan="6">
                <div class="apf-pager-row">
                  <div class="apf-pager-row__right">
                    <div class="apf-pager" id="apfPager" aria-label="Paginação da lista">
                      <button type="button" class="apf-btn apf-btn--ghost apf-pager__btn" id="apfPagerPrev" aria-label="Página anterior">&larr;</button>
                      <span class="apf-pager__label" id="apfPagerLabel">1/1</span>
                      <button type="button" class="apf-btn apf-btn--ghost apf-pager__btn" id="apfPagerNext" aria-label="Próxima página">&rarr;</button>
                    </div>
                  </div>
                </div>
              </th>
            </tr>
            <tr>
              <th scope="col">Tipo</th>
              <th scope="col">Nome/Empresa</th>
              <th scope="col">E-mail</th>
              <th scope="col" class="apf-col--num">Valor (R$)</th>
              <th scope="col">Curso</th>
              <th scope="col">Ações</th>
            </tr>
          </thead>
          <tbody>
          <?php if ( ! empty( $group_rows ) ) :
              foreach ( $group_rows as $bundle ) :
                $payload      = $bundle['latest']['payload'];
                $data_json    = esc_attr( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );
                $history_json = esc_attr( wp_json_encode( $bundle['history'], JSON_UNESCAPED_UNICODE ) );
                $director_key = $bundle['director_key'];
                $director_label = $bundle['director_label'];
                $count_badge  = (int) $bundle['count'];
                $envios_label = $count_badge > 1 ? $count_badge . ' envios' : '';
                $search_blob  = $bundle['search'];
                $nome_display    = ( ! empty( $payload['Nome do colaborador'] ) && $payload['Nome do colaborador'] !== '—' )
                    ? $payload['Nome do colaborador']
                    : $payload['Nome/Empresa'];
                $empresa_display = isset( $payload['Empresa (PJ)'] ) ? trim( (string) $payload['Empresa (PJ)'] ) : '';
          ?>
            <tr class="apf-row-main" data-search="<?php echo esc_attr( $search_blob ); ?>" data-json="<?php echo $data_json; ?>" data-history="<?php echo $history_json; ?>" data-director-key="<?php echo esc_attr( $director_key ); ?>" data-director-label="<?php echo esc_attr( $director_label ); ?>" data-total="<?php echo esc_attr( $count_badge ); ?>" data-group-key="<?php echo esc_attr( $bundle['group_key'] ); ?>" data-latest-id="<?php echo esc_attr( $bundle['latest']['id'] ?? 0 ); ?>">
              <td class="apf-uppercase"><?php echo esc_html( $payload['Tipo'] ); ?></td>
              <td class="apf-break">
                <div class="apf-table__title"><?php echo esc_html( $nome_display ); ?></div>
                <?php if ( 'PJ' === strtoupper( (string) $payload['Tipo'] ) && $empresa_display && $empresa_display !== $nome_display ) : ?>
                  <div class="apf-table__subtitle"><?php echo esc_html( $empresa_display ); ?></div>
                <?php endif; ?>
              </td>
              <td class="apf-break apf-nowrap apf-email"><?php echo esc_html( $payload['E-mail'] ); ?></td>
              <td class="apf-col--num apf-nowrap" title="<?php echo esc_attr( $payload['Valor (R$)'] ); ?>"><?php echo esc_html( $payload['Valor (R$)'] ); ?></td>
              <td class="apf-break"><?php echo esc_html( $payload['Curso'] ); ?></td>
              <td class="apf-actions">
                <label class="apf-row-select">
                  <input type="checkbox" class="apf-row-checkbox" value="<?php echo esc_attr( $bundle['latest']['id'] ?? 0 ); ?>" disabled>
                  <span>Selecionar</span>
                </label>
                <button type="button" class="apf-link apf-btn--inline apf-btn-details">Detalhes</button>
                <?php if ( $count_badge > 1 ) : ?>
                  <span class="apf-actions__envios"><?php echo esc_html( $envios_label ); ?></span>
                <?php endif; ?>
              </td>
            </tr>
            <tr class="apf-row-mobile" data-search="<?php echo esc_attr( $search_blob ); ?>" data-json="<?php echo $data_json; ?>" data-history="<?php echo $history_json; ?>" data-director-key="<?php echo esc_attr( $director_key ); ?>" data-director-label="<?php echo esc_attr( $director_label ); ?>" data-total="<?php echo esc_attr( $count_badge ); ?>" data-group-key="<?php echo esc_attr( $bundle['group_key'] ); ?>" data-latest-id="<?php echo esc_attr( $bundle['latest']['id'] ?? 0 ); ?>">
              <td colspan="6">
                <button type="button" class="apf-row-mobile__toggle" aria-expanded="false">
                  <span class="apf-row-mobile__summary">
                    <strong><?php echo esc_html( $payload['Tipo'] ); ?></strong>
                    <span class="apf-row-mobile__separator">|</span>
                    <span class="apf-row-mobile__name"><?php echo esc_html( $nome_display ); ?></span>
                  </span>
                  <span class="apf-row-mobile__chevron" aria-hidden="true"></span>
                </button>
                <div class="apf-row-mobile__panel" hidden>
                  <div class="apf-row-mobile__line"><span>Tipo</span><strong><?php echo esc_html( $payload['Tipo'] ); ?></strong></div>
                  <div class="apf-row-mobile__line"><span>Nome/Empresa</span><strong><?php echo esc_html( $nome_display ); ?></strong></div>
                  <?php if ( 'PJ' === strtoupper( (string) $payload['Tipo'] ) && $empresa_display && $empresa_display !== $nome_display ) : ?>
                    <div class="apf-row-mobile__line"><span>Empresa</span><strong><?php echo esc_html( $empresa_display ); ?></strong></div>
                  <?php endif; ?>
                  <div class="apf-row-mobile__line"><span>E-mail</span><span class="apf-row-mobile__value apf-row-mobile__value--email"><?php echo esc_html( $payload['E-mail'] ); ?></span></div>
                  <div class="apf-row-mobile__line"><span>Valor</span><strong><?php echo esc_html( $payload['Valor (R$)'] ); ?></strong></div>
                  <div class="apf-row-mobile__line"><span>Curso</span><strong><?php echo esc_html( $payload['Curso'] ); ?></strong></div>
                  <div class="apf-row-mobile__line apf-row-mobile__line--split">
                    <button type="button" class="apf-link apf-btn--inline apf-btn-details">Detalhes</button>
                    <?php if ( $envios_label ) : ?>
                      <span class="apf-actions__envios"><?php echo esc_html( $envios_label ); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6">Nenhuma submissão encontrada.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

      <!-- Modal de Detalhes -->
      <div class="apf-modal" id="apfModal" aria-hidden="true">
        <div class="apf-modal__overlay" data-close></div>
        <div class="apf-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfModalTitle">
          <div class="apf-modal__header">
            <h3 id="apfModalTitle">Detalhes da requisição</h3>
            <div class="apf-modal__header-right">
              <div class="apf-modal__pager" id="apfModalPager">
                <button type="button" class="apf-modal__nav" id="apfModalPrev" data-nav="prev" aria-label="Ver envio mais recente">&larr;</button>
                <span class="apf-modal__counter" id="apfModalCounter"></span>
                <button type="button" class="apf-modal__nav" id="apfModalNext" data-nav="next" aria-label="Ver envio mais antigo">&rarr;</button>
              </div>
              <button class="apf-modal__close" type="button" aria-label="Fechar" data-close>&times;</button>
            </div>
          </div>
          <div class="apf-modal__body">
            <dl class="apf-details" id="apfDetails"></dl>
          </div>
          <div class="apf-modal__footer">
            <a id="apfAdminLink" class="apf-btn apf-btn--ghost" href="#" target="_blank" rel="noopener">Ver no Admin</a>
            <button class="apf-btn apf-btn--primary" type="button" data-close>Fechar</button>
          </div>
        </div>
      </div>

      <?php $archived_return_ids_attr = ! empty( $archived_return_ids ) ? implode( ',', $archived_return_ids ) : ''; ?>
      <?php if ( $faepa_notice ) : ?>
        <div class="apf-coord-return__notice apf-coord-return__notice--<?php echo esc_attr( $faepa_notice_type ); ?>">
          <?php echo esc_html( $faepa_notice ); ?>
        </div>
      <?php endif; ?>
      <section class="apf-coord-return" id="apfCoordReturn" aria-labelledby="apfCoordReturnTitle">
        <div class="apf-coord-return__head">
          <div>
            <h2 id="apfCoordReturnTitle">Retorno dos coordenadores</h2>
            <p>Acompanhe os lotes finalizados pelos coordenadores e já reenviados ao financeiro.</p>
          </div>
        <?php if ( ! empty( $coordinator_return_groups ) ) : ?>
            <div class="apf-coord-return__summary">
              <?php if ( ! empty( $archived_return_groups ) ) : ?>
                <button type="button"
                        class="apf-coord-return__archive-icon-btn"
                        data-coord-archive="<?php echo esc_attr( $archived_return_ids_attr ); ?>"
                        aria-label="Abrir retornos arquivados">
                  <img src="<?php echo esc_url( $coord_archive_icon_url ); ?>" alt="">
                </button>
              <?php endif; ?>
              <span class="apf-coord-return__badge"><?php echo esc_html( count( $coordinator_return_groups ) . ' retorno(s)' ); ?></span>
            </div>
          <?php endif; ?>
        </div>
        <?php if ( ! empty( $visible_return_groups ) ) : ?>
          <div class="apf-coord-return__controls">
            <label class="apf-coord-return__filter">
              <span>Filtrar por mês</span>
              <select id="apfCoordReturnMonth">
                <option value=""><?php echo esc_html( 'Todos os meses' ); ?></option>
                <?php foreach ( $coord_return_months as $month_key => $month_label ) : ?>
                  <option value="<?php echo esc_attr( $month_key ); ?>"><?php echo esc_html( $month_label ); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="apf-coord-return__pager" aria-label="Paginação dos retornos">
              <button type="button" class="apf-btn apf-btn--ghost apf-coord-return__pager-btn" id="apfCoordReturnPrev" aria-label="Página anterior">&larr;</button>
              <span id="apfCoordReturnLabel">1/1</span>
              <button type="button" class="apf-btn apf-btn--ghost apf-coord-return__pager-btn" id="apfCoordReturnNext" aria-label="Próxima página">&rarr;</button>
            </div>
          </div>
          <div class="apf-coord-return__grid">
            <?php foreach ( $visible_return_groups as $return_group ) :
                $total    = isset( $return_group['counts']['total'] ) ? (int) $return_group['counts']['total'] : 0;
                $approved = isset( $return_group['counts']['approved'] ) ? (int) $return_group['counts']['approved'] : 0;
                $rejected = isset( $return_group['counts']['rejected'] ) ? (int) $return_group['counts']['rejected'] : 0;
                $pending  = isset( $return_group['counts']['pending'] ) ? (int) $return_group['counts']['pending'] : 0;
                $submitted= isset( $return_group['submitted_label'] ) ? $return_group['submitted_label'] : '';
                $course   = isset( $return_group['coordinator']['course'] ) ? $return_group['coordinator']['course'] : '';
                $month_key = '';
                if ( ! empty( $return_group['submitted_at'] ) ) {
                    $month_key = date_i18n( 'Y-m', (int) $return_group['submitted_at'] );
                }
            ?>
              <article class="apf-coord-return__card" data-coord-group="<?php echo esc_attr( $return_group['id'] ); ?>" data-coord-month="<?php echo esc_attr( $month_key ); ?>" data-coord-submitted="<?php echo esc_attr( $return_group['submitted_at'] ?? '' ); ?>">
                <header>
                  <div>
                    <h3><?php echo esc_html( $return_group['title'] ); ?></h3>
                    <?php if ( $submitted ) : ?>
                      <small>Enviado em <?php echo esc_html( $submitted ); ?></small>
                    <?php endif; ?>
                    <?php if ( $course ) : ?>
                      <p class="apf-coord-return__course"><?php echo esc_html( $course ); ?></p>
                    <?php endif; ?>
                    <?php if ( ! empty( $return_group['faepa_forwarded'] ) ) : ?>
                      <p class="apf-coord-return__faepa">Enviado à FAEPA<?php echo $return_group['faepa_forwarded_label'] ? ' em ' . esc_html( $return_group['faepa_forwarded_label'] ) : ''; ?></p>
                    <?php endif; ?>
                  </div>
                  <span class="apf-coord-return__total"><?php echo esc_html( $total . ' colaborador' . ( $total === 1 ? '' : 'es' ) ); ?></span>
                </header>
                <button type="button"
                        class="apf-btn apf-btn--ghost apf-coord-return__details"
                        data-coord-group="<?php echo esc_attr( $return_group['id'] ); ?>">
                  Ver detalhes
                </button>
              </article>
          <?php endforeach; ?>
          </div>
          <p class="apf-coord-return__empty" id="apfCoordReturnEmpty" hidden> Nenhum retorno encontrado para o filtro selecionado.</p>
        <?php else : ?>
          <p class="apf-coord-return__empty">Nenhum retorno enviado pelos coordenadores até o momento.</p>
        <?php endif; ?>
      </section>

      <div class="apf-coord-return-modal" id="apfCoordReturnModal" aria-hidden="true">
        <div class="apf-coord-return-modal__overlay" data-coord-return-close></div>
        <div class="apf-coord-return-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfCoordModalTitle" tabindex="-1">
          <div class="apf-coord-return-modal__head">
            <div>
              <h3 id="apfCoordModalTitle">Detalhes do retorno</h3>
              <p id="apfCoordModalSubtitle"></p>
              <div id="apfCoordModalCounts" class="apf-coord-return-modal__counts">
                <span class="apf-coord-return-modal__count-box apf-coord-return-modal__count-box--approved" data-count-approved>
                  <strong>0</strong>
                  <span>Aprovados</span>
                </span>
                <span class="apf-coord-return-modal__count-box apf-coord-return-modal__count-box--rejected" data-count-rejected>
                  <strong>0</strong>
                  <span>Recusados</span>
                </span>
              </div>
            </div>
            <button type="button" class="apf-coord-return-modal__close" data-coord-return-close aria-label="Fechar">&times;</button>
          </div>
          <div class="apf-coord-return-modal__body" id="apfCoordModalBody">
            <p class="apf-coord-return-modal__empty">Selecione um retorno para ver os detalhes.</p>
          </div>
          <div class="apf-coord-return-modal__footer" id="apfCoordFaepaBox" hidden>
            <div class="apf-coord-faepa__status" id="apfCoordFaepaStatus"></div>
            <form method="post" class="apf-coord-faepa__form" id="apfCoordFaepaForm">
              <?php wp_nonce_field( 'apf_faepa_forward', 'apf_faepa_nonce' ); ?>
              <input type="hidden" name="apf_faepa_forward_action" value="1">
              <input type="hidden" name="apf_faepa_batch_id" value="">
              <p class="apf-coord-faepa__hint" data-faepa-hint>Confirme apenas quando todos os colaboradores estiverem aprovados.</p>
              <button type="submit" class="apf-btn apf-btn--primary" id="apfCoordFaepaSubmit">Confirmar e enviar para a FAEPA</button>
            </form>
          </div>
        </div>
      </div>

      <div class="apf-coord-archive-modal" id="apfCoordArchiveModal" aria-hidden="true">
        <div class="apf-coord-archive-modal__overlay" data-coord-archive-close></div>
        <div class="apf-coord-archive-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="apfCoordArchiveTitle" tabindex="-1">
          <div class="apf-coord-archive-modal__head">
            <div>
              <h3 id="apfCoordArchiveTitle">Retornos arquivados</h3>
              <p id="apfCoordArchiveSubtitle">Selecione um lote para ver os detalhes.</p>
            </div>
            <button type="button" class="apf-coord-archive-modal__close" data-coord-archive-close aria-label="Fechar">&times;</button>
          </div>
          <div class="apf-coord-archive-modal__body" id="apfCoordArchiveBody">
            <p class="apf-coord-archive__empty">Nenhum retorno arquivado disponível.</p>
          </div>
        </div>
      </div>

      <?php if ( ! empty( $coordinator_return_groups ) ) : ?>
        <script type="application/json" id="apfCoordReturnData"><?php echo wp_json_encode( $coordinator_return_groups, JSON_UNESCAPED_UNICODE ); ?></script>
      <?php endif; ?>
    </div>

  </div>

    </div>


    <style>
      /* ===== Tokens / dark mode */
      .apf-inbox-theme{
        --apf-bg:#ffffff; --apf-text:#111827; --apf-muted:#6b7280;
        --apf-border:#e5e7eb; --apf-soft:#f8fafc; --apf-primary:#1f6feb; --apf-primary-ink:#ffffff;
        --apf-focus:0 0 0 3px rgba(31,111,235,.2); --apf-shadow:0 1px 2px rgba(16,24,40,.06),0 1px 3px rgba(16,24,40,.1);
        --apf-radius:12px; --apf-radius-sm:10px; --apf-row:#fcfdff; --apf-row-hover:#f3f7ff; --apf-highlight:#fff1a8;
        --apf-modal-overlay: rgba(2,6,23,.55);
      }
      @media (prefers-color-scheme: dark){
        .apf-inbox-theme{
          --apf-bg:#0b1220; --apf-text:#e5e7eb; --apf-muted:#9ca3af; --apf-border:#1f2937; --apf-soft:#0f172a;
          --apf-row:#0c1628; --apf-row-hover:#12203a; --apf-primary:#3b82f6; --apf-primary-ink:#0b1220;
          --apf-highlight:#3a2f00; --apf-shadow:none; --apf-modal-overlay: rgba(0,0,0,.65);
        }
      }

      .apf-inbox-wrap{
        font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial;
        color:var(--apf-text);
        padding:8px 0 24px;
      }

      /* ===== Busca */
      .apf-toolbar{ display:flex; flex-wrap:wrap; align-items:flex-end; gap:16px; margin:8px 0 14px; width:100%; }
      .apf-search{
        display:flex;
        flex:1 1 520px;
        gap:12px;
        align-items:flex-end;
        flex-wrap:wrap;
        min-width:0;
      }
      .apf-search-field{ flex:1 1 320px; min-width:220px; }
      .apf-filter{ display:flex; flex-direction:column; gap:4px; flex:0 1 200px; min-width:180px; width:auto; max-width:240px; }
      .apf-filter span{ font-size:12px; color:var(--apf-muted); font-weight:500; }
      .apf-filter select{
        height:42px; border:1px solid var(--apf-border); border-radius:10px;
        background:var(--apf-bg); color:var(--apf-text);
        padding:0 14px; font-size:14px; min-width:0; width:100%; max-width:240px;
      }
      .apf-filter select:focus{ border-color:var(--apf-primary); box-shadow:var(--apf-focus); outline:none; }
      .apf-filter__row{ display:flex; gap:10px; align-items:stretch; flex-wrap:wrap; }
      .apf-filter__row select{ flex:1 1 240px; min-width:200px; }
      .apf-filter__assign-btn,
      #apfAssignStart{
        padding:0 12px;
        line-height:1.3;
        font-size:12px;
        white-space:normal;
        word-break:normal;
        text-align:left;
        display:inline-flex;
        align-items:center;
        justify-content:flex-start;
        width:100%;
        flex:0 1 360px;
      }
      .apf-pager-row{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:12px;
        margin:6px 0 10px;
        flex-wrap:wrap;
      }
      .apf-pager-row__left{
        flex:1 1 260px;
        min-width:200px;
        display:flex;
        align-items:flex-start;
      }
      .apf-pager-row__right{
        flex:1 1 320px;
        display:flex;
        align-items:center;
        gap:12px;
        justify-content:flex-end;
        flex-wrap:wrap;
      }
      .apf-pager{
        display:flex;
        align-items:center;
        gap:6px;
        height:42px;
        padding:0 4px;
      }
      .apf-pager__btn{
        width:38px;
        min-width:38px;
        height:38px !important;
        padding:0;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:16px;
      }
      .apf-pager__label{
        min-width:64px;
        text-align:center;
        font-size:12px;
        font-weight:600;
        color:var(--apf-muted);
      }
      .apf-search input{
        width:100%; height:44px; border:1px solid var(--apf-border); border-radius:999px;
        padding:0 16px; font-size:15px; background:var(--apf-bg); color:var(--apf-text);
        outline:none; box-shadow:none;
      }
      .apf-search input::placeholder{ color:var(--apf-muted); }
      .apf-search input:focus{ box-shadow:var(--apf-focus); border-color:var(--apf-primary); }
      .apf-search-actions{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; flex:1 1 180px; }
      .apf-btn{
        height:42px; padding:0 14px;
        border:1px solid var(--apf-border);
        border-radius:10px;
        background:var(--apf-soft);
        color:var(--apf-text);
        cursor:pointer;
        transition:background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
      }
      .apf-btn--primary{
        background:#2271b1;
        border-color:#1b5d91;
        color:#ffffff;
      }
      .apf-btn--primary:hover,
      .apf-btn--primary:focus{
        background:#1b5d91;
        border-color:#154a74;
        color:#ffffff;
        box-shadow:var(--apf-focus);
      }
      .apf-btn--ghost{
        background:#f6f7f7;
        border-color:#c3c4c7;
        color:#1d2327;
      }
      .apf-btn--ghost:hover,
      .apf-btn--ghost:focus{
        background:#dcdcde;
        border-color:#a7aaad;
        color:#1d2327;
        box-shadow:var(--apf-focus);
      }
      .apf-btn--danger{
        background:#c81a1a;
        border-color:#991313;
        color:#ffffff;
      }
      .apf-btn--danger:hover,
      .apf-btn--danger:focus{
        background:#991313;
        border-color:#7d0f0f;
        color:#ffffff;
        box-shadow:var(--apf-focus);
      }
      .apf-btn--outline-white{
        background:transparent;
        color:#ffffff;
        border:1px solid #ffffff;
      }
      .apf-btn--outline-white:hover,
      .apf-btn--outline-white:focus{
        background:rgba(255,255,255,0.08);
        color:#ffffff;
        border-color:#ffffff;
        box-shadow:var(--apf-focus);
      }
      .apf-btn--white-dark{
        background:#ffffff;
        color:#111827;
        border:1px solid #ffffff;
      }
      .apf-btn--white-dark:hover,
      .apf-btn--white-dark:focus{
        background:#f3f4f6;
        color:#0f172a;
        border-color:#e5e7eb;
        box-shadow:var(--apf-focus);
      }
      .apf-btn--success{
        background:#15803d;
        border-color:#166534;
        color:#ffffff;
      }
      .apf-btn--success:hover,
      .apf-btn--success:focus{
        background:#166534;
        border-color:#14532d;
        color:#ffffff;
        box-shadow:var(--apf-focus);
      }
      .apf-btn--warning{
        background:#b45309;
        border-color:#92400e;
        color:#ffffff;
      }
      .apf-btn--warning:hover,
      .apf-btn--warning:focus{
        background:#92400e;
        border-color:#7c2d12;
        color:#ffffff;
        box-shadow:var(--apf-focus);
      }
      .apf-assign-form{ display:none; }
      .apf-assign-panel{
        display:none;
        align-items:center;
        justify-content:space-between;
        gap:16px;
        border:1px dashed var(--apf-border);
        border-radius:var(--apf-radius);
        padding:14px 16px;
        margin:12px 0;
        background:var(--apf-soft);
      }
      .apf-assign-panel__info p{
        margin:4px 0 0;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-assign-panel__actions{
        display:flex;
        align-items:center;
        gap:10px;
        flex-wrap:wrap;
      }
      .apf-assign-panel__count{
        font-size:13px;
        color:var(--apf-text);
      }
      .apf-assign-mode #apfAssignPanel{
        display:flex;
      }
      .apf-row-select{
        display:none;
        align-items:center;
        gap:6px;
        font-size:13px;
        color:var(--apf-text);
      }
      .apf-row-select input{
        width:16px;
        height:16px;
        accent-color:#1f6feb;
      }
      .apf-assign-mode .apf-row-select{
        display:inline-flex;
      }
      .apf-assign-mode .apf-btn-details{
        display:none;
      }
      .apf-row-selected{
        background:rgba(34,113,177,.08);
      }
      .apf-row-selected td{
        border-bottom-color:#c3dafe;
      }
      .apf-assign-modal{
        position:fixed;
        inset:0;
        z-index:1000;
        display:flex;
        align-items:center;
        justify-content:center;
        background:var(--apf-modal-overlay, rgba(2,6,23,.55));
        visibility:hidden;
        opacity:0;
        transition:opacity .25s ease;
        padding:16px;
        box-sizing:border-box;
        overflow-y:auto;
      }
      .apf-assign-modal[aria-hidden="false"]{
        visibility:visible;
        opacity:1;
      }
      .apf-assign-modal__overlay{
        position:absolute;
        inset:0;
      }
      .apf-assign-modal__dialog{
        position:relative;
        width:100%;
        max-width:420px;
        background:var(--apf-bg);
        border:1px solid var(--apf-border);
        border-radius:16px;
        padding:0;
        box-shadow:0 20px 40px rgba(15,23,42,.35);
        display:flex;
        flex-direction:column;
        overflow:hidden;
        max-height:calc(100vh - 32px);
        box-sizing:border-box;
      }
      .apf-assign-modal__head{
        padding:16px 20px;
        border-bottom:1px solid var(--apf-border);
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
      }
      .apf-assign-modal__head h3{
        margin:0;
        font-size:18px;
      }
      .apf-assign-modal__close{
        border:none;
        background:transparent;
        font-size:24px;
        line-height:1;
        cursor:pointer;
        color:var(--apf-muted);
      }
      .apf-assign-modal__body{
        padding:20px;
        display:flex;
        flex-direction:column;
        gap:12px;
        flex:1 1 auto;
        min-height:0;
        overflow:auto;
      }
      .apf-assign-modal__body label{
        display:flex;
        flex-direction:column;
        gap:6px;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-assign-modal__body select{
        border:1px solid var(--apf-border);
        border-radius:10px;
        padding:10px 12px;
        font-size:14px;
      }
      .apf-assign-modal__footer{
        padding:16px 20px;
        border-top:1px solid var(--apf-border);
        display:flex;
        justify-content:flex-end;
        gap:10px;
      }
      .apf-assign-modal--note .apf-assign-modal__dialog{
        max-width:520px;
      }
      .apf-director-modal .apf-assign-modal__dialog{
        max-width:560px;
        max-height:calc(100vh - 32px);
      }
      .apf-director-modal .apf-assign-modal__body{
        flex:1 1 auto;
        min-height:0;
        overflow:auto;
      }
      .apf-assign-note__field{
        display:flex;
        flex-direction:column;
        gap:6px;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-assign-note__field + .apf-assign-note__field{
        margin-top:8px;
      }
      .apf-assign-note__field input,
      .apf-assign-note__field textarea{
        border:1px solid var(--apf-border);
        border-radius:10px;
        padding:10px 12px;
        font-size:14px;
        background:var(--apf-bg);
        color:var(--apf-text);
        resize:vertical;
        min-height:44px;
      }
      .apf-assign-note__field textarea{
        min-height:120px;
      }
      .apf-assign-note__field input:focus,
      .apf-assign-note__field textarea:focus{
        border-color:var(--apf-primary);
        box-shadow:var(--apf-focus);
        outline:none;
      }
      .apf-director-modal .apf-directors__grid{
        grid-template-columns:1fr;
        row-gap:12px;
        width:100%;
      }
      .apf-director-modal .apf-assign-modal__head{
        justify-content:center;
        text-align:center;
      }
      .apf-director-modal .apf-assign-modal__head h3{
        text-align:center;
      }
      .apf-director-modal .apf-directors__grid label{
        margin:0;
        width:100%;
      }
      .apf-director-modal .apf-directors__grid input,
      .apf-director-modal .apf-directors__grid select{
        width:100%;
        display:block;
        box-sizing:border-box;
      }
      .apf-director-list-btn{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        width:42px;
        height:42px;
        padding:0;
        background:transparent;
        border:1px solid var(--apf-border);
        border-radius:50%;
        box-shadow:none;
        transition:background-color .15s ease, border-color .15s ease;
      }
      .apf-director-list-btn__icon{
        width:18px;
        height:18px;
        object-fit:contain;
      }
      .apf-director-list-btn:hover,
      .apf-director-list-btn:focus{
        background:rgba(0,0,0,0.02);
        border-color:var(--apf-primary);
        outline:none;
      }
      .apf-director-list-modal .apf-assign-modal__dialog{
        max-width:720px;
        max-height:calc(100vh - 32px);
        display:flex;
        flex-direction:column;
      }
      .apf-director-list-modal .apf-assign-modal__body{
        flex:1 1 auto;
        min-height:0;
      }
      .apf-director-list__body{
        display:flex;
        flex-direction:column;
        gap:12px;
        flex:1 1 auto;
        min-height:0;
        overflow:auto;
        max-height:440px;
      }
      .apf-director-list__search{
        display:flex;
        flex-direction:column;
        gap:6px;
        font-size:13px;
        color:var(--apf-muted);
        margin-bottom:12px;
      }
      .apf-director-list__search input{
        border:1px solid var(--apf-border);
        border-radius:10px;
        padding:10px 12px;
        font-size:14px;
        background:var(--apf-bg);
        color:var(--apf-text);
        width:100%;
        box-sizing:border-box;
      }
      .apf-director-card{
        border:1px solid var(--apf-border);
        border-radius:12px;
        padding:12px;
        display:flex;
        flex-direction:column;
        align-items:stretch;
        gap:12px;
        background:var(--apf-bg);
        width:100%;
        box-sizing:border-box;
      }
      .apf-director-card__fields{
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:12px;
        flex:1 1 auto;
        min-width:0;
        width:100%;
      }
      @media(max-width:680px){
        .apf-director-card__fields{
          grid-template-columns:1fr;
          min-width:0;
        }
      }
      .apf-director-card__field{
        display:flex;
        flex-direction:column;
        gap:4px;
      }
      .apf-director-card__field--full{
        grid-column:1 / -1;
      }
      .apf-director-card__field span{
        font-size:12px;
        color:var(--apf-muted);
        font-weight:600;
      }
      .apf-director-card__field input,
      .apf-director-card__field select{
        border:1px solid var(--apf-border);
        border-radius:10px;
        padding:10px 12px;
        font-size:14px;
        background:var(--apf-bg);
        color:var(--apf-text);
        width:100%;
        max-width:100%;
        min-width:0;
        box-sizing:border-box;
      }
      .apf-director-card__footer{
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:12px;
        flex-wrap:wrap;
        min-width:0;
        width:100%;
      }
      .apf-director-card__actions{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        justify-content:flex-end;
        margin-left:auto;
      }
      .apf-director-card__actions [data-director-edit],
      .apf-director-card__actions [data-director-delete],
      .apf-director-card__actions [data-director-save],
      .apf-director-card__actions [data-director-cancel]{
        min-width:0;
      }
      .apf-director-card__actions [data-director-edit]{
        background:transparent;
        color:#ffffff;
        border:1px solid #ffffff;
      }
      .apf-director-card__actions [data-director-edit]:hover,
      .apf-director-card__actions [data-director-edit]:focus{
        background:rgba(255,255,255,0.08);
        color:#ffffff;
        border-color:#ffffff;
        box-shadow:var(--apf-focus);
      }
      .apf-director-card__actions [data-director-delete]{
        background:#ffffff;
        color:#111827;
        border:1px solid #ffffff;
      }
      .apf-director-card__actions [data-director-delete]:hover,
      .apf-director-card__actions [data-director-delete]:focus{
        background:#f3f4f6;
        color:#0f172a;
        border-color:#e5e7eb;
        box-shadow:var(--apf-focus);
      }
      @media(max-width:780px){
        .apf-assign-modal{
          align-items:center;
          padding:16px;
        }
        .apf-assign-modal__dialog{
          max-width:100%;
          border-radius:16px;
          box-shadow:0 18px 32px rgba(15,23,42,.28);
        }
        .apf-assign-modal__head,
        .apf-assign-modal__footer{
          padding:14px 16px;
        }
        .apf-assign-modal__body{
          padding:16px;
        }
        .apf-director-modal .apf-assign-modal__footer{
          justify-content:center;
        }
        .apf-director-modal .apf-assign-modal__dialog,
        .apf-director-list-modal .apf-assign-modal__dialog{
          max-height:calc(100vh - 20px);
        }
        .apf-director-list-modal .apf-assign-modal__body{
          padding:16px;
        }
        .apf-director-list__body{
          max-height:55vh;
        }
        .apf-directors__head{
          flex-direction:column;
          align-items:center;
          text-align:center;
        }
        .apf-directors__head-actions{
          width:100%;
          justify-content:center;
        }
        .apf-director-list-btn{
          flex:0 0 44px;
        }
        .apf-directors__head-actions .apf-btn--primary{
          flex:1 1 auto;
        }
        .apf-director-card__actions{
          width:100%;
          justify-content:stretch;
          gap:10px;
          flex-wrap: nowrap;
        }
        .apf-director-card__actions .apf-btn{
          width: 50%;
          min-width:0;
        }
      }
      @media(min-width:1300px){
        .apf-director-list__body{
          max-height:none;
        }
      }
      .apf-director-list__hint{
        margin:4px 0 0;
        color:var(--apf-muted);
        font-size:13px;
      }
      .apf-assign-note__hint{
        margin:0;
        font-size:12px;
        color:var(--apf-muted);
      }
      #apfBtnSearch{
        background:var(--apf-bg);
        border-color:var(--apf-border);
        color:var(--apf-text);
      }
      #apfBtnSearch:hover,
      #apfBtnSearch:focus{
        background:var(--apf-soft);
        border-color:#a7aaad;
        color:var(--apf-text);
      }
      .apf-directors{
        margin-bottom:24px;
        padding:18px;
        border:1px solid var(--apf-border);
        border-radius:var(--apf-radius);
        background:var(--apf-soft);
      }
      .apf-directors__head{
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        text-align:center;
        gap:12px;
        flex-wrap:wrap;
      }
      .apf-directors__head-actions{
        display:flex;
        align-items:center;
        gap:8px;
        justify-content:center;
        width:100%;
      }
      @media(min-width:781px){
        .apf-directors__head{
          flex-direction:row;
          align-items:flex-start;
          justify-content:space-between;
          text-align:left;
        }
        .apf-directors__head-actions{
          width:auto;
          justify-content:flex-end;
        }
      }
      .apf-directors__head h2{
        margin:0;
        font-size:18px;
      }
      .apf-directors__head p{
        margin:4px 0 0;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-directors__notice{
        margin:14px 0;
        padding:10px 14px;
        border-radius:var(--apf-radius-sm);
        border:1px solid;
        font-size:13px;
        transition:opacity .3s ease, transform .3s ease;
      }
      .apf-directors__notice--success{
        background:#ecfdf3;
        border-color:#bbf7d0;
        color:#166534;
      }
      .apf-directors__notice--error{
        background:#fef2f2;
        border-color:#fecaca;
        color:#991b1b;
      }
      .apf-notice{
        margin:18px 0;
        padding:12px 14px;
        border-radius:var(--apf-radius-sm);
        border:1px solid;
        font-size:13px;
        transition:opacity .3s ease, transform .3s ease;
      }
      .apf-notice--success{
        background:#ecfdf3;
        border-color:#bbf7d0;
        color:#166534;
      }
      .apf-notice--error{
        background:#fef2f2;
        border-color:#fecaca;
        color:#991b1b;
      }
      .apf-scheduler{
        margin:32px 0;
        padding:20px;
        border:1px solid var(--apf-border);
        border-radius:var(--apf-radius);
        background:var(--apf-bg);
        box-shadow:var(--apf-shadow);
        display:flex;
        flex-direction:column;
        gap:20px;
      }
      .apf-scheduler__head h2{
        margin:0;
        font-size:18px;
      }
      .apf-scheduler__head p{
        margin:6px 0 0;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-scheduler__grid{
        display:grid;
        grid-template-columns:2fr 1fr;
        gap:20px;
        align-items:flex-start;
      }
      .apf-scheduler__calendar{
        border:1px solid var(--apf-border);
        border-radius:var(--apf-radius-sm);
        background:var(--apf-soft);
        padding:16px;
      }
      .apf-scheduler__calendar-inner{
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-scheduler__calendar-loading{
        text-align:center;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-scheduler__calendar-header{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
      }
      .apf-scheduler__calendar-header h3{
        margin:0;
        font-size:16px;
        font-weight:600;
      }
      .apf-scheduler__calendar-nav{
        display:flex;
        align-items:center;
        gap:8px;
      }
      .apf-scheduler__nav-btn{
        width:32px;
        height:32px;
        border-radius:50%;
        border:1px solid var(--apf-border);
        background:var(--apf-bg);
        color:var(--apf-text);
        cursor:pointer;
        display:flex;
        align-items:center;
        justify-content:center;
        transition:background-color .15s ease, border-color .15s ease, color .15s ease;
      }
      .apf-scheduler__nav-btn:hover,
      .apf-scheduler__nav-btn:focus{
        border-color:var(--apf-primary);
        color:var(--apf-primary);
        outline:none;
      }
      .apf-scheduler__weekdays,
      .apf-scheduler__days{
        display:grid;
        grid-template-columns:repeat(7,1fr);
        gap:6px;
      }
      .apf-scheduler__weekday{
        text-align:center;
        font-size:12px;
        font-weight:600;
        color:var(--apf-muted);
        text-transform:uppercase;
      }
      .apf-scheduler__day{
        position:relative;
        display:flex;
        align-items:center;
        justify-content:center;
        padding:0;
        height:48px;
        border-radius:10px;
        border:1px solid var(--apf-border);
        background:var(--apf-bg);
        font-size:14px;
        font-weight:600;
        color:var(--apf-text);
        cursor:pointer;
        transition:background-color .15s ease, border-color .15s ease, transform .15s ease;
      }
      .apf-scheduler__day:not(:disabled):hover,
      .apf-scheduler__day:not(:disabled):focus{
        border-color:var(--apf-primary);
        outline:none;
        transform:translateY(-1px);
      }
      .apf-scheduler__day:disabled{
        cursor:not-allowed;
        opacity:.35;
      }
      .apf-scheduler__day--selected{
        border-color:var(--apf-primary);
        background:rgba(34,113,177,.15);
      }
      .apf-scheduler__day-marker{
        position:absolute;
        inset:6px 6px auto auto;
        width:10px;
        height:10px;
        border-radius:50%;
        background:#f97316;
      }
      .apf-scheduler__day-marker--providers{
        background:#1f6feb;
      }
      .apf-scheduler__day-marker--coordinators{
        background:#f97316;
      }
      .apf-scheduler__form{
        border:1px solid var(--apf-border);
        border-radius:var(--apf-radius-sm);
        background:var(--apf-soft);
        padding:16px;
        display:flex;
        flex-direction:column;
        gap:14px;
      }
      .apf-scheduler__selected{
        display:flex;
        align-items:center;
        gap:8px;
        font-size:14px;
      }
      .apf-scheduler__audience{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-scheduler__tabs{
        display:flex;
        gap:6px;
        padding:4px;
        border-radius:999px;
        background:var(--apf-bg);
        border:1px solid var(--apf-border);
      }
      .apf-scheduler__tab{
        border:none;
        background:transparent;
        border-radius:999px;
        padding:6px 14px;
        font-size:13px;
        cursor:pointer;
        color:var(--apf-muted);
        transition:background-color .15s ease, color .15s ease, box-shadow .15s ease;
      }
      .apf-scheduler__tab.is-active{
        background:var(--apf-primary);
        color:var(--apf-primary-ink);
        box-shadow:var(--apf-focus);
      }
      .apf-scheduler__tab:disabled,
      .apf-scheduler__tab.is-disabled{
        opacity:.5;
        cursor:not-allowed;
      }
      .apf-scheduler__selected-label{
        color:var(--apf-muted);
      }
      .apf-scheduler__field{
        display:flex;
        flex-direction:column;
        gap:6px;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-scheduler__field input{
        border:1px solid var(--apf-border);
        border-radius:10px;
        padding:10px 12px;
        font-size:14px;
        background:var(--apf-bg);
        color:var(--apf-text);
      }
      .apf-scheduler__field input:focus{
        border-color:var(--apf-primary);
        box-shadow:var(--apf-focus);
        outline:none;
      }
      .apf-scheduler__providers{
        border:1px solid var(--apf-border);
        border-radius:10px;
        max-height:220px;
        overflow:auto;
        background:var(--apf-bg);
        padding:8px;
        display:grid;
        gap:6px;
        grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
      }
      #apfSchedulerRecipientsHolder{
        display:none;
      }
      .apf-scheduler__providers-empty{
        margin:0;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-scheduler__provider{
        display:flex;
        align-items:center;
        gap:8px;
        padding:8px;
        border-radius:8px;
        background:var(--apf-soft);
        border:1px solid transparent;
        border-left:4px solid transparent;
        cursor:pointer;
        font-size:13px;
      }
      .apf-scheduler__provider input{
        pointer-events:none;
      }
      .apf-scheduler__provider.is-selected{
        border-color:var(--apf-primary);
        background:rgba(34,113,177,.12);
      }
      .apf-scheduler__provider--providers{
        border-left-color:#1f6feb;
      }
      .apf-scheduler__provider--coordinators{
        border-left-color:#f97316;
        background:rgba(249,115,22,.08);
      }
      .apf-scheduler__actions{
        display:flex;
        justify-content:flex-end;
        flex-wrap:wrap;
        gap:8px;
      }
      .apf-scheduler-modal{
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
      .apf-scheduler-modal[aria-hidden="false"]{
        visibility:visible;
        opacity:1;
        pointer-events:auto;
      }
      .apf-scheduler-modal__overlay{
        position:absolute;
        inset:0;
        background:rgba(15,23,42,.55);
      }
      .apf-scheduler-modal__dialog{
        position:relative;
        width:90%;
        max-width:760px;
        max-height:90vh;
        background:#fff;
        border-radius:18px;
        box-shadow:0 24px 60px rgba(15,23,42,.35);
        display:flex;
        flex-direction:column;
        overflow:hidden;
      }
      .apf-scheduler-modal__head{
        padding:14px 18px;
        border-bottom:1px solid #e4e7ec;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
      }
      .apf-scheduler-modal__head h3{
        margin:0;
        font-size:18px;
        color:#0f172a;
      }
      .apf-scheduler-modal__head-actions{
        margin-left:auto;
        display:flex;
        align-items:center;
        gap:12px;
      }
      .apf-scheduler-modal__new{
        white-space:nowrap;
      }
      .apf-scheduler-modal__close{
        border:none;
        background:transparent;
        font-size:24px;
        line-height:1;
        cursor:pointer;
        color:#475467;
      }
      .apf-scheduler-modal__content{
        padding:14px 18px 20px;
        overflow-y:auto;
        flex:1;
      }
      .apf-scheduler-modal__empty{
        margin:0;
        font-size:14px;
        color:#475467;
        text-align:center;
      }
      .apf-scheduler-modal__items{
        display:flex;
        flex-direction:column;
        gap:12px;
        max-height:420px;
        overflow:auto;
      }
      .apf-scheduler-modal__event{
        border:1px solid #e4e7ec;
        border-radius:14px;
        padding:12px;
        background:#f9fafb;
        display:flex;
        flex-direction:column;
        gap:8px;
      }
      .apf-scheduler-modal__event h4{
        margin:0;
        font-size:14px;
        color:#0f172a;
      }
      .apf-scheduler-modal__event-meta{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        font-size:12.5px;
        color:#475467;
      }
      .apf-scheduler-modal__event-actions{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
      }
      .apf-scheduler-edit{
        display:flex;
        flex-direction:column;
        gap:16px;
      }
      .apf-scheduler-edit__section{
        display:flex;
        flex-direction:column;
        gap:6px;
      }
      .apf-scheduler-edit__date-label{
        font-size:12px;
        text-transform:uppercase;
        letter-spacing:.02em;
        color:#475467;
      }
      .apf-scheduler-edit__field{
        display:flex;
        flex-direction:column;
        gap:6px;
        font-size:13px;
        color:#344054;
      }
      .apf-scheduler-edit__field input{
        border:1px solid #d0d5dd;
        border-radius:10px;
        padding:9px 12px;
        font-size:14px;
      }
      .apf-scheduler-edit__chips{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        min-height:32px;
      }
      .apf-scheduler-edit__chips-empty{
        margin:0;
        font-size:13px;
        color:#667085;
      }
      .apf-scheduler-edit__chip{
        display:inline-flex;
        align-items:center;
        gap:6px;
        background:#e0f2fe;
        color:#0f172a;
        border-radius:999px;
        padding:6px 10px;
        font-size:12px;
      }
      .apf-scheduler-edit__chip button{
        border:none;
        background:transparent;
        color:inherit;
        cursor:pointer;
        font-size:12px;
      }
      .apf-scheduler-edit__audience{
        display:flex;
        flex-direction:column;
        gap:6px;
      }
      .apf-scheduler-edit__tabs{
        display:flex;
        gap:8px;
      }
      .apf-scheduler-edit__tab{
        flex:1;
        border:1px solid #d0d5dd;
        border-radius:999px;
        padding:8px 12px;
        background:#fff;
        font-size:13px;
        cursor:pointer;
      }
      .apf-scheduler-edit__tab.is-active{
        background:#1f6feb;
        color:#fff;
        border-color:#1f6feb;
      }
      .apf-scheduler-edit__tab.is-disabled{
        opacity:.45;
        cursor:not-allowed;
      }
      .apf-scheduler-edit__providers{
        border:1px solid #e4e7ec;
        border-radius:12px;
        padding:8px;
        background:#fff;
        max-height:180px;
        overflow:auto;
        display:flex;
        flex-direction:column;
        gap:4px;
      }
      .apf-scheduler-edit__provider{
        border:1px solid transparent;
        border-radius:10px;
        padding:8px 10px;
        font-size:13px;
        color:#0f172a;
        cursor:pointer;
        display:flex;
        justify-content:space-between;
        gap:8px;
        background:#fff;
        text-align:left;
      }
      .apf-scheduler-edit__provider:hover{
        border-color:#d0d5dd;
        background:#f8fafc;
      }
      .apf-scheduler-edit__provider.is-selected{
        border-color:#1d4ed8;
        background:#eff4ff;
      }
      .apf-scheduler-edit__providers-empty{
        margin:8px 0;
        text-align:center;
        font-size:13px;
        color:#475467;
      }
      .apf-scheduler-edit__footer{
        display:flex;
        justify-content:flex-end;
        gap:12px;
      }
      .apf-scheduler-hidden-form{
        display:none;
      }
      @media(max-width:1080px){
        .apf-scheduler__grid{
          grid-template-columns:1fr;
        }
      }
      @media(max-width:960px){
        .apf-scheduler{
          padding:16px;
          gap:16px;
        }
        .apf-scheduler__grid{
          gap:16px;
        }
        .apf-scheduler__calendar,
        .apf-scheduler__form{
          padding:14px;
        }
        .apf-scheduler__selected{
          flex-direction:column;
          align-items:flex-start;
          gap:6px;
        }
        .apf-scheduler__audience{
          flex-direction:column;
          align-items:flex-start;
          gap:8px;
        }
        .apf-scheduler__tabs{
          width:100%;
        }
        .apf-scheduler__providers{
          grid-template-columns:1fr;
          max-height:240px;
        }
        .apf-scheduler__actions{
          justify-content:stretch;
        }
        .apf-scheduler__actions .apf-btn{
          width:100%;
        }
      }
      @media(max-width:768px){
        .apf-scheduler{
          font-size:15px;
        }
        .apf-scheduler__head h2{
          font-size:17px;
        }
        .apf-scheduler__head p{
          font-size:13px;
        }
        .apf-scheduler__calendar-header h3{
          font-size:15px;
        }
      }
      @media(max-width:720px){
        .apf-scheduler{
          padding:14px;
          font-size:14px;
        }
        .apf-scheduler__calendar,
        .apf-scheduler__form{
          padding:12px;
        }
        .apf-scheduler__calendar-header{
          flex-direction:column;
          align-items:flex-start;
        }
        .apf-scheduler__weekdays,
        .apf-scheduler__days{
          gap:4px;
        }
        .apf-scheduler__day{
          height:42px;
          font-size:13px;
        }
        .apf-scheduler__actions{
          flex-direction:column;
          align-items:stretch;
        }
        .apf-scheduler__providers{
          max-height:200px;
        }
        .apf-scheduler-modal{
          align-items:center;
          padding:12px;
        }
        .apf-scheduler-modal__dialog{
          max-width:90%;
          max-height:90vh;
          border-radius:14px;
        }
        .apf-scheduler-modal__head,
        .apf-scheduler-modal__content{
          padding:14px 16px;
        }
        .apf-scheduler-modal__head-actions{
          flex-wrap:wrap;
          justify-content:flex-end;
        }
        .apf-scheduler-modal__items{
          max-height:320px;
        }
        .apf-scheduler-modal__event{
          padding:12px;
        }
      }
      @media(max-width:700px){
        .apf-scheduler-modal__event h4{
          font-size:13px;
        }
        .apf-scheduler-modal__event-meta{
          font-size:12px;
          flex-direction:column;
          align-items:flex-start;
        }
        .apf-scheduler-modal__event{
          padding:11px;
        }
        .apf-scheduler-modal__event-actions{
          gap:8px;
        }
        .apf-scheduler-modal__event-actions .apf-btn{
          height:38px;
          padding:0 12px;
          font-size:13px;
        }
      }
      @media(max-width:640px){
        .apf-scheduler{
          padding:12px;
          font-size:13px;
        }
        .apf-scheduler__head h2{
          font-size:16px;
        }
        .apf-scheduler__head p{
          font-size:12px;
        }
        .apf-scheduler__calendar,
        .apf-scheduler__form{
          padding:10px;
        }
        .apf-scheduler__calendar-header h3{
          font-size:14px;
        }
        .apf-scheduler__weekdays,
        .apf-scheduler__days{
          gap:3px;
        }
        .apf-scheduler__weekday{
          font-size:11px;
        }
        .apf-scheduler__day{
          height:38px;
          font-size:12px;
        }
        .apf-scheduler__providers{
          max-height:180px;
        }
        .apf-scheduler-modal__head h3{
          font-size:16px;
        }
        .apf-scheduler-modal__items{
          max-height:260px;
        }
        .apf-scheduler-modal__event{
          padding:11px;
          gap:6px;
        }
        .apf-scheduler-modal__event h4{
          font-size:12.5px;
        }
        .apf-scheduler-modal__event-meta{
          font-size:11.5px;
        }
      }
      @media(max-width:540px){
        .apf-scheduler{
          font-size:12.5px;
        }
        .apf-scheduler__head h2{
          font-size:15px;
        }
        .apf-scheduler__calendar,
        .apf-scheduler__form{
          padding:10px;
        }
        .apf-scheduler__weekdays,
        .apf-scheduler__days{
          grid-template-columns:repeat(7,minmax(30px,1fr));
        }
        .apf-scheduler__day{
          height:36px;
          font-size:11.5px;
        }
        .apf-scheduler__weekday{
          font-size:10.5px;
        }
        .apf-scheduler__providers{
          max-height:160px;
        }
        .apf-scheduler-modal__items{
          max-height:220px;
        }
        .apf-scheduler-modal__head h3{
          font-size:15px;
        }
      }
      @media(max-width:480px){
        .apf-scheduler{
          padding:10px;
          font-size:12px;
        }
        .apf-scheduler__head h2{
          font-size:14px;
        }
        .apf-scheduler__head p{
          font-size:11px;
        }
        .apf-scheduler__calendar,
        .apf-scheduler__form{
          padding:9px;
        }
        .apf-scheduler__day{
          height:34px;
          font-size:11px;
        }
        .apf-scheduler__weekday{
          font-size:10px;
        }
        .apf-scheduler-modal__items{
          max-height:200px;
        }
        .apf-scheduler-modal__head,
        .apf-scheduler-modal__content{
          padding:12px 14px;
        }
        .apf-scheduler-modal__event{
          padding:10px;
        }
      }
      @media(max-width:400px){
        .apf-scheduler-edit__provider{
          flex-direction:column;
          align-items:flex-start;
          gap:4px;
        }
        .apf-scheduler-edit__provider span{
          width:100%;
          text-align:left;
        }
        .apf-scheduler-edit__provider small{
          white-space:normal;
        }
        .apf-scheduler-modal__new{
          padding:0 8px;
          height:34px;
          font-size:12px;
          width:100%;
        }
        .apf-scheduler-modal__head{
          position:relative;
          flex-direction:row;
          align-items:center;
          gap:8px;
        }
        .apf-scheduler-modal__head-actions{
          margin-left:0;
          width:100%;
          justify-content:flex-start;
          gap:8px;
          padding-right:42px;
          flex-wrap:wrap;
        }
        .apf-scheduler-modal__close{
          position:absolute;
          right:10px;
          top:50%;
          transform:translateY(-50%);
        }
        .apf-scheduler-modal__new{
          order:2;
        }
      }
      @media(max-width:375px){
        .apf-scheduler{
          padding:8px;
          font-size:11.5px;
        }
        .apf-scheduler__calendar,
        .apf-scheduler__form{
          padding:8px;
        }
        .apf-scheduler__calendar-header{
          flex-direction:column;
          align-items:flex-start;
          gap:6px;
        }
        .apf-scheduler__calendar-header h3{
          font-size:13px;
        }
        .apf-scheduler__nav-btn{
          width:28px;
          height:28px;
        }
        .apf-scheduler__weekdays,
        .apf-scheduler__days{
          grid-template-columns:repeat(7,minmax(24px,1fr));
          gap:2px;
        }
        .apf-scheduler__weekday{
          font-size:10px;
        }
        .apf-scheduler__day{
          height:32px;
          font-size:11px;
        }
        .apf-scheduler__audience{
          font-size:11.5px;
        }
        .apf-scheduler__tab{
          padding:5px 10px;
          font-size:12px;
          white-space:nowrap;
        }
      }
      @media(max-width:330px){
        .apf-scheduler{
          padding:6px;
        }
        .apf-scheduler__calendar,
        .apf-scheduler__form{
          padding:8px;
          margin:0 auto;
        }
        .apf-scheduler__calendar{
          max-width:280px;
        }
        .apf-scheduler__calendar-header{
          align-items:flex-start;
        }
        .apf-scheduler__weekdays,
        .apf-scheduler__days{
          grid-template-columns:repeat(7,minmax(22px,1fr));
          justify-items:stretch;
        }
        .apf-scheduler__day{
          width:100%;
          height:30px;
          font-size:10.5px;
        }
      }
      @media (prefers-color-scheme: dark){
        .apf-directors{
          background:var(--apf-bg);
        }
        .apf-directors__notice--success{
          background:rgba(22,101,52,.2);
          border-color:rgba(74,222,128,.3);
          color:#bbf7d0;
        }
        .apf-directors__notice--error{
          background:rgba(153,27,27,.25);
          border-color:rgba(248,113,113,.35);
          color:#fecaca;
        }
        .apf-notice--success{
          background:rgba(22,101,52,.2);
          border-color:rgba(74,222,128,.3);
          color:#bbf7d0;
        }
        .apf-notice--error{
          background:rgba(153,27,27,.25);
          border-color:rgba(248,113,113,.35);
          color:#fecaca;
        }
        .apf-scheduler{
          background:var(--apf-bg);
        }
        .apf-scheduler__calendar,
        .apf-scheduler__form{
          background:var(--apf-row);
        }
      }
      .apf-directors__form,
      .apf-directors__item{
        display:flex;
        flex-direction:column;
        gap:12px;
        margin-top:16px;
        padding:16px;
        border:1px solid var(--apf-border);
        border-radius:var(--apf-radius-sm);
        background:var(--apf-bg);
      }
      .apf-directors__grid{
        display:grid;
        grid-template-columns:1fr;
        gap:12px;
      }
      .apf-directors__grid label{
        display:flex;
        flex-direction:column;
        gap:4px;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-directors__grid input,
      .apf-directors__grid select{
        border:1px solid var(--apf-border);
        border-radius:10px;
        padding:10px 12px;
        font-size:14px;
        background:var(--apf-bg);
        color:var(--apf-text);
        width:100%;
        max-width:100%;
      }
      .apf-directors__grid select{
        appearance:none;
        -webkit-appearance:none;
        -moz-appearance:none;
        background-image:linear-gradient(45deg,transparent 50%,var(--apf-muted) 50%),linear-gradient(135deg,var(--apf-muted) 50%,transparent 50%),linear-gradient(to right,transparent,transparent);
        background-position:calc(100% - 18px) 55%,calc(100% - 13px) 55%,100% 0;
        background-size:5px 5px,5px 5px,40px 100%;
        background-repeat:no-repeat;
        padding-right:42px;
      }
          </div>
        </section>
  
      .apf-directors__grid input:focus,
      .apf-directors__grid select:focus{
        outline:none;
        border-color:var(--apf-primary);
        box-shadow:var(--apf-focus);
      }
      .apf-directors__actions,
      .apf-directors__item-actions{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
      }
      .apf-directors__item-actions{
        justify-content:flex-end;
        align-items:center;
      }
      .apf-directors__status{
        margin-right:auto;
        font-size:12px;
        font-weight:600;
        padding:4px 10px;
        border-radius:999px;
        background:#e5e7eb;
        color:#111827;
      }
      .apf-directors__status--approved{
        background:#dcfce7;
        color:#166534;
      }
      .apf-directors__status--pending{
        background:#fef3c7;
        color:#92400e;
      }
      .apf-directors__status--rejected{
        background:#fee2e2;
        color:#b42318;
      }
      .apf-directors__list{
        display:flex;
        flex-direction:column;
        gap:12px;
        margin-top:12px;
      }
      @media(min-width:720px){
        .apf-directors__grid{
          grid-template-columns:1fr 1fr;
        }
      }
      .apf-toolbar__meta{ flex:0 0 auto; }
      .apf-count{ font-size:13px; color:var(--apf-muted); }
      .apf-badge{ display:inline-block; padding:4px 10px; border-radius:999px; background:var(--apf-soft); border:1px solid var(--apf-border); }

      /* ===== Tabela (enxuta) */
      .apf-table-scroller{
        overflow:auto;
        border:1px solid var(--apf-border);
        border-radius:var(--apf-radius);
        background:var(--apf-bg);
        box-shadow:var(--apf-shadow);
        flex:1 1 auto;
        min-width:0;
      }
      .apf-table{ width:100%; border-collapse:collapse; border-spacing:0; min-width:760px; }
      th, td{ word-break: normal; hyphens: manual; line-height:1.35; }
      .apf-table thead th{
        position:sticky; top:0; z-index:1; background:var(--apf-soft);
        text-align:center; padding:16px 18px; border:1px solid var(--apf-border); font-weight:700; color:#475467; font-size:18px;
      }
      @media (prefers-color-scheme: dark){ .apf-table thead th{ color:#cbd5e1; } }
      .apf-table tbody td{
        padding:16px 18px;
        border:1px solid var(--apf-border);
        border-bottom:0;
        vertical-align:middle;
        text-align:center;
        font-size:18px;
      }
      .apf-row-pager th,
      .apf-row-pager td{
        background:var(--apf-soft);
        font-size:16px;
        text-align:left;
        position:static;
        top:auto;
        border:1px solid var(--apf-border);
        padding:10px 12px;
      }
      .apf-row-pager .apf-pager-row{ margin:0; }
      .apf-table tbody tr:nth-child(odd){ background:var(--apf-row); }
      .apf-table tbody tr:hover{ background:var(--apf-row-hover); }
      .apf-table__title{
        font-weight:700;
        color:var(--apf-text);
        font-size:18px;
        display:block;
        text-align:center;
        margin-bottom:6px;
      }
      .apf-table__subtitle{
        display:block;
        font-size:18px;
        color:var(--apf-muted);
        margin-top:4px;
        text-align:center;
      }
      .apf-row-mobile{ display:none; }

      .apf-col--num{ text-align:center; font-variant-numeric:tabular-nums; }
      .apf-nowrap{ white-space:nowrap; text-overflow:ellipsis; overflow:hidden; max-width:220px; }
      .apf-break{ overflow-wrap:anywhere; }
      .apf-actions{
        display:flex;
        flex-direction:column;
        gap:8px;
        align-items:center;
        justify-content:center;
      }
      .apf-actions__envios{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        padding:2px 8px;
        border-radius:999px;
        background:var(--apf-soft);
        border:1px solid var(--apf-border);
        font-size:11px;
        font-weight:600;
        color:var(--apf-muted);
        text-transform:uppercase;
        letter-spacing:.03em;
        line-height:1;
        white-space:nowrap;
      }
      .apf-link{ color:var(--apf-primary); text-decoration:none; font-weight:500; }
      .apf-link:hover{ text-decoration:underline; }
      .apf-btn--inline{ background:transparent; border:none; padding:0; height:auto; }
      .apf-assign-inline-desktop{ display:inline-flex; align-items:center; }
      .apf-table-meta{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:8px;
        margin:4px 0 8px;
      }
      .apf-table-meta{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:8px;
        margin:4px 0 8px;
      }

      /* Highlight */
      .apf-hide{ display:none !important; }
      .apf-page-hide{ display:none !important; }
      .apf-highlight{ background:linear-gradient(transparent 60%, var(--apf-highlight) 0); }

      /* ===== Modal de detalhes */
      .apf-modal[aria-hidden="true"]{ display:none; }
      .apf-modal{ position:fixed; inset:0; display:grid; place-items:center; z-index:9999; }
      .apf-modal__overlay{ position:absolute; inset:0; background:var(--apf-modal-overlay); }
      .apf-modal__dialog{
        position:relative; max-width:720px; width:calc(100% - 32px);
        background:var(--apf-bg); color:var(--apf-text); border:1px solid var(--apf-border);
        border-radius:16px; box-shadow:var(--apf-shadow);
      }
      .apf-modal__header, .apf-modal__footer{ padding:14px 16px; border-bottom:1px solid var(--apf-border); }
      .apf-modal__footer{ border-top:1px solid var(--apf-border); border-bottom:0; display:flex; justify-content:flex-end; gap:8px; }
      .apf-modal__body{ padding:8px 16px 16px; max-height:70vh; overflow:auto; }
      .apf-modal__close{ background:transparent; border:none; font-size:26px; line-height:1; cursor:pointer; color:var(--apf-muted); }
      .apf-modal__header{ display:flex; align-items:center; justify-content:space-between; gap:12px; }
      .apf-modal__header-right{ display:flex; align-items:center; gap:12px; }
      .apf-modal__pager{ display:flex; align-items:center; gap:8px; font-size:12px; color:var(--apf-muted); }
      .apf-modal__nav{
        width:32px; height:32px;
        border:1px solid var(--apf-border);
        border-radius:50%;
        display:flex; align-items:center; justify-content:center;
        background:var(--apf-bg);
        color:var(--apf-muted);
        cursor:pointer;
        transition:background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
      }
      .apf-modal__nav:disabled{
        opacity:.4;
        cursor:not-allowed;
      }
      .apf-modal__nav:not(:disabled):hover,
      .apf-modal__nav:not(:disabled):focus{
        border-color:var(--apf-primary);
        color:var(--apf-primary);
        box-shadow:var(--apf-focus);
        outline:none;
      }
      .apf-modal__counter{ font-weight:600; min-width:120px; text-align:center; }
      .apf-details{ display:grid; grid-template-columns: 220px 1fr; gap:10px 16px; }
      .apf-details dt{
        color:var(--apf-muted);
        padding-bottom:6px;
        border-bottom:1px solid var(--apf-border);
      }
      .apf-details dd{
        margin:0;
        padding-bottom:6px;
        border-bottom:1px solid var(--apf-border);
      }
      .apf-details dt:last-of-type,
      .apf-details dd:last-of-type{
        border-bottom:none;
        padding-bottom:0;
      }

      @media(max-width:720px){
        .apf-modal{ align-items:flex-start; }
        .apf-modal__dialog{
          width:calc(100% - 16px);
          max-width:none;
          margin:12px auto;
        }
        .apf-modal__header,
        .apf-modal__footer{
          padding:12px;
          flex-wrap:wrap;
          gap:10px;
        }
        .apf-modal__header-right{
          width:100%;
          justify-content:space-between;
        }
        .apf-modal__pager{
          width:100%;
          justify-content:space-between;
        }
        .apf-modal__nav{ width:28px; height:28px; }
        .apf-modal__counter{ min-width:0; text-align:left; font-size:12px; }
        .apf-modal__body{
          padding:12px;
          max-height:60vh;
        }
        .apf-modal__footer{ justify-content:flex-end; }
        .apf-details{
          grid-template-columns:1fr;
          gap:8px 0;
        }
        .apf-details dt{
          border-bottom:0;
          padding-bottom:0;
          margin:0;
        }
        .apf-details dd{
          border-bottom:1px solid var(--apf-border);
          padding:0 0 8px;
          margin:0 0 6px;
        }
        .apf-details dd:last-of-type{
          border-bottom:0;
          padding-bottom:0;
          margin-bottom:0;
        }
      }

      /* ===== Retorno dos coordenadores */
      .apf-coord-return{
        margin:32px 0 0;
        padding:20px;
        border:1px solid var(--apf-border);
        border-radius:var(--apf-radius);
        background:var(--apf-bg);
        box-shadow:var(--apf-shadow);
        display:flex;
        flex-direction:column;
        gap:16px;
      }
      .apf-coord-return__head{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:16px;
      }
      .apf-coord-return__head h2{
        margin:0;
        font-size:18px;
      }
      .apf-coord-return__head p{
        margin:6px 0 0;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-coord-return__badge{
        border:1px solid var(--apf-border);
        border-radius:999px;
        padding:6px 14px;
        font-size:12px;
        font-weight:600;
        color:var(--apf-muted);
        background:var(--apf-soft);
        white-space:nowrap;
      }
      .apf-coord-return__controls{
        display:flex;
        align-items:flex-end;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:12px;
      }
      .apf-coord-return__filter{
        display:flex;
        flex-direction:column;
        gap:6px;
        min-width:210px;
        font-size:12px;
        color:var(--apf-muted);
      }
      .apf-coord-return__filter select{
        height:38px;
        border:1px solid var(--apf-border);
        border-radius:10px;
        padding:0 12px;
        background:var(--apf-bg);
        color:var(--apf-text);
      }
      .apf-coord-return__filter select:focus{
        border-color:var(--apf-primary);
        box-shadow:var(--apf-focus);
        outline:none;
      }
      .apf-coord-return__pager{
        display:flex;
        align-items:center;
        gap:8px;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-coord-return__pager-btn{
        min-width:36px;
        padding:6px 10px;
      }
      .apf-coord-return__hidden{ display:none !important; }
      .apf-coord-return__grid{
        display:grid;
        grid-template-columns:1fr;
        gap:16px;
      }
      .apf-coord-return__card{
        border:1px solid var(--apf-border);
        border-radius:var(--apf-radius-sm);
        padding:12px;
        background:var(--apf-soft);
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-coord-return__card header{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
      }
      .apf-coord-return__card h3{
        margin:0;
        font-size:16px;
      }
      .apf-coord-return__card small{
        display:block;
        margin-top:4px;
        font-size:12px;
        color:var(--apf-muted);
      }
      .apf-coord-return__course{
        margin:6px 0 0;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-coord-return__total{
        font-size:13px;
        font-weight:600;
        color:var(--apf-primary);
        white-space:nowrap;
      }
      .apf-coord-return__stats{
        list-style:none;
        margin:0;
        padding:0;
        display:flex;
        gap:12px;
      }
      .apf-coord-return__stats li{
        flex:1;
        border:1px solid var(--apf-border);
        border-radius:10px;
        padding:8px;
        background:var(--apf-bg);
        text-align:center;
      }
      .apf-coord-return__details{
        margin-top:auto;
        align-self:flex-start;
        min-width:140px;
      }
      .apf-coord-return__summary{
        display:flex;
        align-items:center;
        gap:6px;
      }
      .apf-coord-return__archive-icon-btn{
        border:none;
        background:transparent;
        padding:0;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        width:30px;
        height:30px;
        border-radius:8px;
        cursor:pointer;
        transition:transform .15s ease, box-shadow .15s ease;
      }
      .apf-coord-return__archive-icon-btn img{
        width:20px;
        height:20px;
        display:block;
      }
      .apf-coord-return__archive-icon-btn:hover,
      .apf-coord-return__archive-icon-btn:focus{
        transform:translateY(-1px);
        box-shadow:0 6px 12px rgba(15,23,42,.25);
        outline:none;
      }
      .apf-coord-return__empty{
        margin:0;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-coord-return__notice{
        margin:8px 0 12px;
        padding:10px 12px;
        border-radius:10px;
        font-size:13px;
        border:1px solid var(--apf-border);
      }
      .apf-coord-return__notice--success{
        background:#f0fdf4;
        border-color:#bbf7d0;
        color:#166534;
      }
      .apf-coord-return__notice--error{
        background:#fef2f2;
        border-color:#fecaca;
        color:#991b1b;
      }
      .apf-coord-return__faepa{
        margin:6px 0 0;
        font-size:12px;
        color:var(--apf-muted);
      }

      /* Modal retorno coordenador */
      .apf-coord-return-modal{
        position:fixed;
        inset:0;
        z-index:9998;
        display:flex;
        align-items:center;
        justify-content:center;
        opacity:0;
        visibility:hidden;
        transition:opacity .2s ease;
        pointer-events:none;
      }
      .apf-coord-return-modal[aria-hidden="false"]{
        opacity:1;
        visibility:visible;
        pointer-events:auto;
      }
      .apf-coord-return-modal__overlay{
        position:absolute;
        inset:0;
        background:var(--apf-modal-overlay);
      }
      .apf-coord-return-modal__dialog{
        position:relative;
        width:min(860px, calc(100% - 32px));
        max-height:90vh;
        background:#0e1627;
        color:#f1f5f9;
        border:1px solid rgba(255,255,255,.08);
        border-radius:18px;
        box-shadow:0 24px 50px rgba(15,23,42,.55);
        display:flex;
        flex-direction:column;
        overflow:hidden;
      }
      .apf-coord-return-modal__head{
        padding:18px 20px;
        border-bottom:1px solid rgba(255,255,255,.08);
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
      }
      .apf-coord-return-modal__head h3{
        margin:0;
        font-size:18px;
        color:#fff;
      }
      .apf-coord-return-modal__head p{
        margin:6px 0 0;
        font-size:13px;
        color:#cbd5f5;
      }
      .apf-coord-return-modal__close{
        border:none;
        background:transparent;
        color:#cbd5f5;
        font-size:28px;
        cursor:pointer;
      }
      .apf-coord-return-modal__body{
        padding:20px;
        overflow:auto;
        display:flex;
        flex-direction:column;
        gap:14px;
      }
      .apf-coord-return-modal__footer{
        padding:16px 20px 20px;
        border-top:1px solid rgba(255,255,255,.08);
        background:#0a1020;
        display:flex;
        flex-direction:column;
        gap:10px;
      }
      .apf-coord-faepa__status{
        font-size:13px;
        font-weight:600;
        color:#22c55e;
      }
      .apf-coord-faepa__status--pending{ color:#fbbf24; }
      .apf-coord-faepa__status--error{ color:#fca5a5; }
      .apf-coord-faepa__form{
        display:flex;
        flex-direction:column;
        gap:10px;
      }
      .apf-coord-faepa__form.is-disabled{
        opacity:.9;
      }
      .apf-coord-faepa__form label{
        display:flex;
        flex-direction:column;
        gap:6px;
        font-size:13px;
        color:#e2e8f0;
      }
      .apf-coord-faepa__hint{margin:0;font-size:12px;color:#cbd5e1}
      .apf-coord-return-modal__empty{
        margin:0;
        font-size:13px;
        color:#cbd5f5;
      }
      .apf-coord-return-modal__counts{
        margin:8px 0 10px;
        display:flex;
        align-items:stretch;
        gap:10px;
      }
      .apf-coord-return-modal__count-box{
        flex:1;
        font-size:13px;
        color:#e2e8f0;
        font-weight:700;
        text-align:center;
        padding:10px 12px;
        border:1px solid rgba(226,232,240,.25);
        border-radius:12px;
        background:rgba(226,232,240,.12);
        display:block;
      }
      .apf-coord-return-modal__count-box strong{
        display:block;
        font-size:20px;
        margin-bottom:4px;
      }
      .apf-coord-return-modal__count-box span{
        display:block;
        text-transform:uppercase;
        letter-spacing:.04em;
        font-size:11px;
      }
      .apf-coord-return-modal__count-box--approved{
        background:rgba(16,185,129,.12);
        border-color:rgba(52,211,153,.5);
        color:#34d399;
      }
      .apf-coord-return-modal__count-box--rejected{
        background:rgba(248,113,113,.12);
        border-color:rgba(248,113,113,.5);
        color:#f87171;
      }
      .apf-coord-archive-modal{
        position:fixed;
        inset:0;
        z-index:9997;
        display:flex;
        align-items:center;
        justify-content:center;
        opacity:0;
        visibility:hidden;
        transition:opacity .2s ease;
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
        background:var(--apf-modal-overlay);
      }
      .apf-coord-archive-modal__dialog{
        position:relative;
        width:min(900px, calc(100% - 32px));
        max-height:92vh;
        background:#0b1220;
        color:#e2e8f0;
        border:1px solid rgba(255,255,255,.08);
        border-radius:22px;
        box-shadow:0 26px 60px rgba(15,23,42,.7);
        display:flex;
        flex-direction:column;
        overflow:hidden;
      }
      .apf-coord-archive-modal__head{
        padding:20px;
        border-bottom:1px solid rgba(255,255,255,.08);
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:16px;
      }
      .apf-coord-archive-modal__head h3{
        margin:0;
        font-size:20px;
        color:#fff;
      }
      .apf-coord-archive-modal__head p{
        margin:6px 0 0;
        font-size:13px;
        color:#94a3b8;
      }
      .apf-coord-archive-modal__close{
        border:none;
        background:transparent;
        color:#cbd5f5;
        font-size:32px;
        cursor:pointer;
      }
      .apf-coord-archive-modal__body{
        padding:20px;
        overflow:auto;
        display:flex;
        flex-direction:column;
        gap:14px;
      }
      .apf-coord-archive__empty{
        margin:0;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-archive-entry{
        border:1px solid rgba(255,255,255,.1);
        border-radius:16px;
        background:rgba(15,15,30,.65);
        overflow:hidden;
      }
      .apf-archive-entry__toggle{
        width:100%;
        border:none;
        background:transparent;
        padding:16px 18px;
        text-align:left;
        display:flex;
        flex-direction:column;
        gap:4px;
        cursor:pointer;
        color:#fff;
      }
      .apf-archive-entry__toggle h4{
        margin:0;
        font-size:15px;
        color:#fff;
      }
      .apf-archive-entry__toggle small{
        color:#cbd5f5;
      }
      .apf-archive-entry__status{
        color:#a5b4fc;
        font-size:12px;
      }
      .apf-archive-entry__chevron{
        width:12px;
        height:12px;
        border-right:2px solid rgba(255,255,255,.5);
        border-bottom:2px solid rgba(255,255,255,.5);
        transform:rotate(45deg);
        margin-left:auto;
        transition:transform .2s ease;
      }
      .apf-archive-entry__toggle[aria-expanded="true"] .apf-archive-entry__chevron{
        transform:rotate(225deg);
      }
      .apf-archive-entry__content{
        border-top:1px solid rgba(255,255,255,.08);
        padding:16px 18px;
        background:rgba(11,18,32,.85);
      }
      .apf-archive-entry__message{
        margin:0 0 12px;
        font-size:13px;
        color:#e2e8f0;
      }
      .apf-archive-collabs{
        display:flex;
        flex-direction:column;
        gap:10px;
      }
      .apf-archive-collab{
        border:1px solid rgba(255,255,255,.08);
        border-radius:12px;
        background:rgba(14,23,41,.8);
        overflow:hidden;
        color:#f8fafc;
      }
      .apf-archive-collab__toggle{
        width:100%;
        border:none;
        background:transparent;
        padding:14px 16px;
        display:flex;
        flex-direction:column;
        gap:6px;
        text-align:left;
        cursor:pointer;
        color:inherit;
      }
      .apf-archive-collab__summary{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
      }
      .apf-archive-collab__summary strong{
        font-size:14px;
      }
      .apf-archive-collab__meta{
        display:flex;
        flex-wrap:wrap;
        gap:10px;
        font-size:12px;
        color:#cbd5f5;
      }
      .apf-archive-collab__pill{
        padding:4px 10px;
        border-radius:999px;
        background:rgba(15,23,42,.5);
        font-weight:600;
        font-size:12px;
        color:#f8fafc;
      }
      .apf-archive-collab__pill--approved{ background:rgba(34,197,94,.2); color:#22c55e; }
      .apf-archive-collab__pill--rejected{ background:rgba(248,113,113,.2); color:#f87171; }
      .apf-archive-collab__pill--pending{ background:rgba(250,204,21,.2); color:#facc15; }
      .apf-archive-collab__chevron{
        width:10px;
        height:10px;
        border-right:2px solid rgba(255,255,255,.5);
        border-bottom:2px solid rgba(255,255,255,.5);
        transform:rotate(45deg);
        margin-left:auto;
        transition:transform .2s ease;
      }
      .apf-archive-collab__toggle[aria-expanded="true"] .apf-archive-collab__chevron{
        transform:rotate(225deg);
      }
      .apf-archive-collab__details{
        border-top:1px solid rgba(255,255,255,.08);
        padding:14px 16px;
        background:rgba(7,12,24,.95);
      }
      .apf-coord-modal__list{
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-coord-modal__entry{
        border:1px solid rgba(255,255,255,.08);
        border-radius:14px;
        background:rgba(10,15,28,.7);
        overflow:hidden;
      }
      .apf-coord-modal__toggle{
        width:100%;
        border:none;
        background:transparent;
        padding:14px 16px;
        display:flex;
        flex-direction:column;
        gap:6px;
        text-align:left;
        cursor:pointer;
      }
      .apf-coord-modal__summary{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        color:#f8fafc;
      }
      .apf-coord-modal__summary strong{
        font-size:15px;
        color:#fff;
      }
      .apf-coord-modal__summary span{
        font-weight:600;
        color:#cbd5f5;
      }
      .apf-coord-modal__meta{
        display:flex;
        flex-wrap:wrap;
        gap:12px;
        font-size:12px;
        color:#cbd5f5;
      }
      .apf-coord-modal__pill{
        padding:4px 10px;
        border-radius:999px;
        font-weight:600;
        font-size:12px;
        background:rgba(15,23,42,.5);
        color:#f8fafc;
      }
      .apf-coord-modal__pill--approved{ background:rgba(34,197,94,.2); color:#22c55e; }
      .apf-coord-modal__pill--rejected{ background:rgba(248,113,113,.2); color:#f87171; }
      .apf-coord-modal__pill--pending{ background:rgba(250,204,21,.2); color:#facc15; }
      .apf-coord-modal__pill--paid{ background:rgba(59,130,246,.2); color:#1d4ed8; }
      .apf-coord-modal__chevron{
        width:12px;
        height:12px;
        border-right:2px solid var(--apf-muted);
        border-bottom:2px solid var(--apf-muted);
        transform:rotate(45deg);
        margin-left:auto;
        transition:transform .2s ease;
      }
      .apf-coord-modal__toggle[aria-expanded="true"] .apf-coord-modal__chevron{
        transform:rotate(225deg);
      }
      .apf-coord-modal__details{
        border-top:1px solid rgba(255,255,255,.08);
        padding:16px;
        background:rgba(8,13,24,.85);
        color:#e2e8f0;
      }
      .apf-coord-modal__note{
        margin:0 0 12px;
        padding:12px;
        border-radius:10px;
        background:rgba(15,23,42,.6);
        color:#f8fafc;
        font-size:13px;
      }
      .apf-coord-modal__section{
        margin:0 0 12px;
      }
      .apf-coord-modal__section h4{
        margin:0 0 8px;
        font-size:13px;
        text-transform:uppercase;
        letter-spacing:.04em;
        color:#cbd5f5;
      }
      .apf-coord-modal__section dl{
        margin:0;
        display:grid;
        grid-template-columns:160px 1fr;
        gap:6px 14px;
      }
      .apf-coord-modal__section dt{
        color:#94a3b8;
        font-size:12px;
      }
      .apf-coord-modal__section dd{
        margin:0;
        font-size:13px;
        color:#f8fafc;
      }
      .apf-coord-modal__admin{
        display:inline-flex;
        align-items:center;
        gap:6px;
        font-size:13px;
        font-weight:600;
        color:#69c1ff;
        text-decoration:none;
      }
      .apf-coord-modal__admin:hover{
        text-decoration:underline;
      }



      /* Mobile accordion */
      @media (max-width: 920px){
        .apf-toolbar{ flex-direction:column; align-items:stretch; gap:8px; }
        .apf-search{ width:100%; flex-direction:column; align-items:stretch; gap:8px; flex:0 0 auto; }
        .apf-search-field{ width:100%; flex:0 0 auto; min-width:0; }
        .apf-filter--assign{ order:0; }
        .apf-search-field{ order:1; }
        .apf-search-actions{ order:2; }
        .apf-search input{ border-radius:10px; }
        .apf-search-actions{ flex-direction:column; align-items:stretch; justify-content:flex-start; gap:8px; width:100%; display:flex; flex:0 0 auto; }
        .apf-search-actions .apf-btn,
        #apfBtnSearch{ height:44px; }
        .apf-filter{ width:100%; flex:0 0 auto; min-width:0; max-width:none; }
        .apf-filter select{ width:100%; max-width:none; min-width:0; height:44px; }
        .apf-filter__row{ gap:8px; flex-direction:column; align-items:stretch; }
        .apf-filter__row select{ flex:0 0 auto; height:45px; min-width:0; }
        .apf-coord-return__head{ flex-direction:column; align-items:flex-start; }
        .apf-coord-return__actions{ flex-direction:column; align-items:flex-start; }
        .apf-coord-return__stats{ flex-direction:column; }
        .apf-coord-return__archive-icon-btn{ margin-right:0; }
        .apf-coord-return__grid{ grid-template-columns:1fr; }
        .apf-table{ min-width:100%; border-collapse:separate; }
        .apf-table thead{ display:table-header-group; }
        .apf-table thead tr:not(.apf-row-pager){ display:none; }
        .apf-pager-row{ justify-content:flex-end; gap:0; }
        .apf-pager-row__right{ justify-content:flex-end; gap:0; }
        .apf-pager{
          height:25px;
          gap:0;
          padding:0;
          justify-content: space-between;
        }
        .apf-pager__btn{
          width:25px !important;
          min-width:25px !important;
          height:26px !important;
          flex:0 0 25px;
          padding:0;
          font-size:14px;
          line-height:25px;
          display:flex;
          align-items:center;
          justify-content:center;
        }
        .apf-pager__label{
          height:25px;
          line-height:25px;
          font-size:14px;
          min-width:52px;
          text-align:center;
        }
        .apf-row-main{ display:none; }
        .apf-row-mobile{ display:table-row; }
        .apf-table tbody tr.apf-row-mobile{ background:transparent; }
        .apf-row-mobile td{ padding:0; border:0; }
        .apf-row-mobile__toggle{
          width:100%;
          box-sizing:border-box;
          border:1px solid var(--apf-border);
          border-radius:12px;
          background:var(--apf-soft);
          padding:14px 14px;
          display:flex;
          align-items:center;
          justify-content:space-between;
          gap:10px;
          font-size:16px;
          color:var(--apf-text);
          cursor:pointer;
          text-align:left;
          max-width:100%;
        }
        .apf-row-mobile__summary{
          display:flex;
          align-items:center;
          gap:8px;
          font-weight:700;
          flex:1 1 auto;
          min-width:0;
          max-width:100%;
        }
        .apf-row-mobile__separator{ color:var(--apf-muted); }
        .apf-row-mobile__name{
          display:block;
          overflow:hidden;
          text-overflow:ellipsis;
          white-space:normal;
          word-break:break-word;
          max-width:100%;
        }
        .apf-row-mobile__chevron{
          width:10px;
          height:10px;
          border-right:2px solid var(--apf-muted);
          border-bottom:2px solid var(--apf-muted);
          transform:rotate(45deg);
          transition:transform .2s ease;
          flex:0 0 auto;
        }
        .apf-row-mobile.is-open .apf-row-mobile__chevron{
          transform:rotate(225deg);
        }
        .apf-row-mobile.is-open .apf-row-mobile__toggle{
          border-bottom-left-radius:0;
          border-bottom-right-radius:0;
          border-bottom-color:transparent;
        }
        .apf-row-mobile__panel{
          box-sizing:border-box;
          border:1px solid var(--apf-border);
          border-top:1px solid var(--apf-border);
          border-radius:0 0 12px 12px;
          background:var(--apf-bg);
          padding:10px 14px 12px;
          display:flex;
          flex-direction:column;
          gap:6px;
        }
        .apf-row-mobile__line{
          display:flex;
          justify-content:space-between;
          gap:12px;
          align-items:flex-start;
          font-size:14px;
          color:var(--apf-text);
          border-top:1px solid var(--apf-border);
          padding-top:8px;
        }
        .apf-row-mobile__line:first-child{ border-top:0; padding-top:0; }
        .apf-row-mobile__line span:first-child{ color:var(--apf-muted); font-weight:600; }
        .apf-row-mobile__value{ text-align:right; }
        .apf-row-mobile__value--email{ word-break:break-word; text-align:right; }
        .apf-row-mobile__line--split{
          align-items:center;
          flex-wrap:wrap;
        }
        .apf-row-mobile__line--split .apf-btn-details{ padding:0; }
        .apf-actions__envios{ margin-left:auto; }
        .apf-nowrap{ max-width:none; white-space:normal; overflow:visible; text-overflow:clip; }
        .apf-email{ white-space:normal; overflow-wrap:anywhere; word-break:break-word; max-width:100%; }
      .apf-row-mobile__panel[hidden]{ display:none !important; }
      }
      @media(max-width:640px){
        .apf-toolbar{ gap:10px; }
        .apf-search{ gap:8px; }
        .apf-search-actions{ width:100%; justify-content:stretch; }
        .apf-search-actions .apf-btn,
        #apfBtnSearch{ flex:0 0 auto; width:100%; height:44px; }
        .apf-filter__row{ flex-direction:column; align-items:stretch; }
        .apf-filter__assign-btn{ width:100%; max-width:none; }
        .apf-pager-row{ flex-direction:column; align-items:flex-end; gap:0; }
        .apf-pager-row__left{ display:none; }
        .apf-pager-row__right{ width:100%; flex:1 1 auto; justify-content:flex-end; gap:0; flex-wrap:nowrap; align-items:center; }
        .apf-count{ order:1; flex:0 0 auto; white-space:nowrap; }
        .apf-pager{ order:3; flex:1 1 auto; min-width:0; justify-content:space-between; padding:0; }
        .apf-table-meta{ justify-content:space-between; gap:8px; flex-wrap:nowrap; }
        .apf-scheduler__audience{ flex-direction:column; align-items:flex-start; gap:8px; }
        .apf-scheduler__tabs{ width:100%; justify-content:space-between; }
        .apf-scheduler__tab{ flex:1 1 auto; text-align:center; }
        .apf-btn{ height:44px; }
        .apf-filter select{ height:44px; }
        .apf-search input{ height:44px; }
      }
      @media(max-width:540px){
        .apf-assign-inline-desktop{
          font-size:12px;
          padding-left:12px;
          padding-right:12px;
          height:38px;
          line-height:1.2;
          white-space:normal;
          min-width:0;
          max-width:none;
        }
      }
      @media(max-width:375px){
        .apf-assign-inline-desktop{
          font-size:11px;
          padding-left:10px;
          padding-right:10px;
          height:36px;
          width:60%;
          min-width:0;
          max-width:none;
        }
      }
      @media(max-width:540px){
        .apf-scheduler__calendar,
        .apf-scheduler__form{ padding:12px; }
        .apf-scheduler__weekdays,
        .apf-scheduler__days{ grid-template-columns:repeat(7,minmax(30px,1fr)); }
        .apf-scheduler__day{
          height:38px;
          font-size:12px;
        }
        .apf-scheduler__weekday{
          font-size:11px;
        }
      }
    </style>

    <script>
    (function(){
      const $ = (s,c)=> (c||document).querySelector(s);
      const $$ = (s,c)=> Array.from((c||document).querySelectorAll(s));

      function norm(s){ return (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim(); }
      function digits(s){ return (s||'').toString().replace(/\D+/g,''); }
      function providerToken(provider, fallback){
        if(!provider){ return ''; }
        const group = provider.group || fallback || 'providers';
        return group + '::' + (provider.key || '');
      }

      const input = $('#apfQuery');
      const btnSearch = $('#apfBtnSearch');
      const btnClear  = $('#apfBtnClear');
      const directorSelect = $('#apfDirectorFilter');
      const rows = $$('#apfTable tbody tr.apf-row-main');
      const countEl = $('#apfCount');
      const pagerPrev = $('#apfPagerPrev');
      const pagerNext = $('#apfPagerNext');
      const pagerLabel = $('#apfPagerLabel');
      const inboxWrap = $('#apfInboxWrap');
      const assignPanel = $('#apfAssignPanel');
      const assignForm = $('#apfAssignForm');
      const assignFieldCoordinator = $('#apfAssignFieldCoordinator');
      const assignFieldRows = $('#apfAssignFieldRows');
      const assignFieldNoteTitle = $('#apfAssignFieldNoteTitle');
      const assignFieldNoteBody = $('#apfAssignFieldNoteBody');
      const assignStartBtn = $('#apfAssignStart');
      const assignSendBtn = $('#apfAssignSend');
      const assignCancelBtn = $('#apfAssignCancel');
      const assignCount = $('#apfAssignCount');
      const assignHelper = $('#apfAssignHelper');
      const assignCheckboxes = $$('.apf-row-checkbox');
      const getMobileRow = (row)=>{
        const next = row ? row.nextElementSibling : null;
        return (next && next.classList && next.classList.contains('apf-row-mobile')) ? next : null;
      };
      const assignModal = $('#apfAssignModal');
      const assignModalSelect = $('#apfAssignModalSelect');
      const assignModalConfirm = $('#apfAssignModalConfirm');
      const assignModalCancel = $('#apfAssignModalCancel');
      const assignModalCloseTriggers = assignModal ? $$('[data-assign-close]', assignModal) : [];
      const assignNoteModal = $('#apfAssignNoteModal');
      const assignNoteTitleInput = $('#apfAssignNoteTitleInput');
      const assignNoteBodyInput = $('#apfAssignNoteBodyInput');
      const assignNoteConfirm = $('#apfAssignNoteConfirm');
      const assignNoteCancel = assignNoteModal ? assignNoteModal.querySelector('[data-note-cancel]') : null;
      const directorAddBtn = $('#apfDirectorAddBtn');
      const directorModal = $('#apfDirectorModal');
      const directorCloseTriggers = directorModal ? $$('[data-director-modal-close]', directorModal) : [];
      let directorModalLastFocus = null;
      const directorListBtn = $('#apfDirectorListBtn');
      const directorListModal = $('#apfDirectorListModal');
      const directorListCloseTriggers = directorListModal ? $$('[data-director-list-close]', directorListModal) : [];
      const directorForms = directorListModal ? $$('[data-director-form]', directorListModal) : [];
      const directorListSearch = $('#apfDirectorListSearch');
      const directorListBody = $('#apfDirectorListBody');
      let directorListLastFocus = null;
      const assignNoteCloseTriggers = assignNoteModal ? $$('[data-note-close]', assignNoteModal) : [];
      let assignModalLastFocus = null;
      let assignNoteLastFocus = null;
      const assignSelected = new Set();
      let assignMode = false;
      let activeCoordinatorKey = directorSelect ? (directorSelect.value || '') : '';
      const noticeParams = ['apf_dir_notice','apf_dir_status','apf_sched_notice','apf_sched_status','apf_assign_notice','apf_assign_status'];
      const dismissNotices = () => {
        $$('.apf-directors__notice, .apf-notice').forEach(node=>{
          if(node && node.parentNode){
            node.parentNode.removeChild(node);
          }
        });
      };
      const autoCleanNotices = () => {
        if(window.history.replaceState){
          try{
            const url = new URL(window.location.href);
            let updated = false;
            noticeParams.forEach(param=>{
              if(url.searchParams.has(param)){
                url.searchParams.delete(param);
                updated = true;
              }
            });
            if(updated){
              const cleanUrl = url.pathname + (url.search ? url.search : '') + url.hash;
              window.history.replaceState({}, document.title, cleanUrl);
            }
          }catch(_err){}
        }
      };
      autoCleanNotices();
      const noticeForms = $$('.apf-directors__form, .apf-directors__item, #apfSchedulerForm, #apfSchedulerEditForm');
      noticeForms.forEach(form=>{
        if(!form){ return; }
        form.addEventListener('submit', dismissNotices);
        form.addEventListener('input', dismissNotices, { once:true });
      });

      function updateAssignCount(){
        if(!assignCount){ return; }
        const total = assignSelected.size;
        assignCount.textContent = total === 1 ? '1 selecionado' : (total + ' selecionados');
      }

      function updateAssignHelper(){
        if(!assignHelper){ return; }
        if(!assignMode){
          assignHelper.textContent = 'Escolha um coordenador para liberar a seleção de colaboradores.';
          return;
        }
        if(!activeCoordinatorKey){
          assignHelper.textContent = 'Selecione um coordenador/curso no filtro.';
        }else if(assignSelected.size === 0){
          assignHelper.textContent = 'Marque os colaboradores que deseja enviar (sempre o envio mais recente).';
        }else{
          assignHelper.textContent = 'Pronto! Confira e clique em Enviar.';
        }
      }

      function updateAssignActions(){
        if(assignPanel){
          assignPanel.setAttribute('aria-hidden', assignMode ? 'false' : 'true');
        }
        if(assignStartBtn){
          assignStartBtn.disabled = assignMode;
        }
        if(assignSendBtn){
          assignSendBtn.disabled = !(assignMode && activeCoordinatorKey && assignSelected.size > 0);
        }
        if(inboxWrap){
          inboxWrap.classList.toggle('apf-assign-mode', assignMode);
        }
      }

      function clearAssignSelection(){
        assignSelected.clear();
        assignCheckboxes.forEach(cb=>{
          cb.checked = false;
          cb.disabled = true;
          const row = cb.closest('tr');
          if(row){
            row.classList.remove('apf-row-selected');
          }
        });
        updateAssignCount();
      }

      function setActiveCoordinatorKey(value){
        activeCoordinatorKey = value || '';
        assignCheckboxes.forEach(cb=>{
          const shouldEnable = assignMode && !!activeCoordinatorKey;
          cb.disabled = !shouldEnable;
          if(!shouldEnable && cb.checked){
            cb.checked = false;
            assignSelected.delete(cb.value);
            const row = cb.closest('tr');
            if(row){
              row.classList.remove('apf-row-selected');
            }
          }
        });
        updateAssignCount();
        updateAssignHelper();
        updateAssignActions();
      }

      function setDirectorFilterDisabled(disabled){
        if(directorSelect){
          directorSelect.disabled = disabled;
        }
      }

      function setAssignMode(enable){
        assignMode = !!enable;
        setDirectorFilterDisabled(assignMode);
        if(assignStartBtn){
          assignStartBtn.disabled = assignMode;
        }
        if(!assignMode){
          clearAssignSelection();
          if(assignFieldCoordinator){ assignFieldCoordinator.value = ''; }
          if(assignFieldRows){ assignFieldRows.value = ''; }
          resetAssignNoteFields();
          closeAssignNoteModal(false);
        }else{
          assignCheckboxes.forEach(cb=>{
            cb.disabled = !activeCoordinatorKey;
          });
        }
        updateAssignHelper();
        updateAssignActions();
      }

      function openDirectorModal(){
        if(!directorModal){ return; }
        directorModalLastFocus = document.activeElement;
        directorModal.setAttribute('aria-hidden','false');
        document.body.style.overflow = 'hidden';
        const focusable = directorModal.querySelector('select[name="apf_dir_course"]')
          || directorModal.querySelector('input[name="apf_dir_course"]')
          || directorModal.querySelector('input[name="apf_dir_name"]')
          || directorModal;
        focusable.focus();
      }

      function closeDirectorModal(focusBack = true){
        if(!directorModal){ return; }
        directorModal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        if(focusBack && directorModalLastFocus){
          directorModalLastFocus.focus();
        }
      }

      function openDirectorListModal(){
        if(!directorListModal){ return; }
        directorListLastFocus = document.activeElement;
        directorListModal.setAttribute('aria-hidden','false');
        document.body.style.overflow = 'hidden';
        const focusable = directorListModal.querySelector('[data-director-edit]') || directorListModal;
        focusable.focus();
      }

      function closeDirectorListModal(focusBack = true){
        if(!directorListModal){ return; }
        directorListModal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        if(focusBack && directorListLastFocus){
          directorListLastFocus.focus();
        }
      }

      function openAssignModal(){
        if(!assignModal){ return; }
        if(assignModalSelect && directorSelect){
          assignModalSelect.value = directorSelect.value || '';
        }
        assignModal.setAttribute('aria-hidden','false');
        assignModalLastFocus = document.activeElement;
        const focusable = assignModal.querySelector('select') || assignModal;
        focusable.focus();
        document.body.style.overflow = 'hidden';
      }

      function closeAssignModal(focusBack = true){
        if(!assignModal){ return; }
        assignModal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        if(focusBack && assignModalLastFocus){
          assignModalLastFocus.focus();
        }
      }

      function openAssignNoteModal(){
        if(!assignNoteModal){ 
          assignForm && assignForm.submit();
          return;
        }
        assignNoteModal.setAttribute('aria-hidden','false');
        assignNoteLastFocus = document.activeElement;
        document.body.style.overflow = 'hidden';
        if(assignNoteTitleInput){
          assignNoteTitleInput.focus();
        }else if(assignNoteModal){
          assignNoteModal.focus();
        }
      }

      function closeAssignNoteModal(focusBack = true){
        if(!assignNoteModal){ return; }
        assignNoteModal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        if(focusBack && assignNoteLastFocus){
          assignNoteLastFocus.focus();
        }
      }

      function resetAssignNoteFields(){
        if(assignNoteTitleInput){ assignNoteTitleInput.value = ''; }
        if(assignNoteBodyInput){ assignNoteBodyInput.value = ''; }
        if(assignFieldNoteTitle){ assignFieldNoteTitle.value = ''; }
        if(assignFieldNoteBody){ assignFieldNoteBody.value = ''; }
      }

      if(directorAddBtn){
        directorAddBtn.addEventListener('click', openDirectorModal);
      }
      if(directorCloseTriggers.length){
        directorCloseTriggers.forEach(trigger=>{
          trigger.addEventListener('click', ()=>closeDirectorModal());
        });
      }
      if(directorModal){
        directorModal.addEventListener('keydown', ev=>{
          if(ev.key === 'Escape'){
            ev.preventDefault();
            closeDirectorModal();
          }
        });
        const directorForm = directorModal.querySelector('form');
        if(directorForm){
          directorForm.addEventListener('submit', ()=>closeDirectorModal(false));
        }
      }
      if(directorListBtn){
        directorListBtn.addEventListener('click', openDirectorListModal);
      }
      if(directorListCloseTriggers.length){
        directorListCloseTriggers.forEach(trigger=>{
          trigger.addEventListener('click', ()=>closeDirectorListModal());
        });
      }
      if(directorListModal){
        directorListModal.addEventListener('keydown', ev=>{
          if(ev.key === 'Escape'){
            ev.preventDefault();
            closeDirectorListModal();
          }
        });
        if(directorListSearch && directorListBody){
          directorListSearch.addEventListener('input', ()=>{
            const term = norm(directorListSearch.value);
            $$('.apf-director-card', directorListBody).forEach(card=>{
              const inputs = $$('input, select', card);
              const haystack = inputs.map(inp=>norm(inp.value || '')).join(' ');
              const match = !term || haystack.includes(term);
              card.style.display = match ? '' : 'none';
            });
          });
        }
      }

      if(assignStartBtn){
        assignStartBtn.addEventListener('click', ()=>{
          if(!assignModal){
            alert('Cadastre ao menos um coordenador para usar esta função.');
            return;
          }
          openAssignModal();
        });
      }
      if(assignModalConfirm){
        assignModalConfirm.addEventListener('click', ()=>{
          if(!assignModalSelect || !assignModalSelect.value){
            alert('Selecione um coordenador/curso.');
            assignModalSelect && assignModalSelect.focus();
            return;
          }
          const chosen = assignModalSelect.value;
          closeAssignModal(false);
          if(directorSelect){
            directorSelect.value = chosen;
          }
          setActiveCoordinatorKey(chosen);
          runSearch();
          setAssignMode(true);
          if(assignFieldCoordinator){
            assignFieldCoordinator.value = chosen;
          }
        });
      }
      if(assignModalCancel){
        assignModalCancel.addEventListener('click', ()=>{
          closeAssignModal();
        });
      }
      if(assignModalCloseTriggers.length){
        assignModalCloseTriggers.forEach(trigger=>{
          trigger.addEventListener('click', ()=>closeAssignModal());
        });
      }
      document.addEventListener('keydown', e=>{
        if(e.key === 'Escape'){
          if(directorListModal && directorListModal.getAttribute('aria-hidden') === 'false'){
            e.preventDefault();
            closeDirectorListModal();
            return;
          }
          if(directorModal && directorModal.getAttribute('aria-hidden') === 'false'){
            e.preventDefault();
            closeDirectorModal();
            return;
          }
          if(assignModal && assignModal.getAttribute('aria-hidden') === 'false'){
            e.preventDefault();
            closeAssignModal();
            return;
          }
          if(assignNoteModal && assignNoteModal.getAttribute('aria-hidden') === 'false'){
            e.preventDefault();
            closeAssignNoteModal();
          }
        }
      });

      if(assignNoteConfirm){
        assignNoteConfirm.addEventListener('click', ()=>{
          if(!assignForm || !assignFieldCoordinator || !assignFieldRows){ return; }
          if(assignFieldNoteTitle && assignNoteTitleInput){
            assignFieldNoteTitle.value = assignNoteTitleInput.value.trim();
          }
          if(assignFieldNoteBody && assignNoteBodyInput){
            assignFieldNoteBody.value = assignNoteBodyInput.value.trim();
          }
          assignForm.submit();
        });
      }

      if(directorForms && directorForms.length){
        directorForms.forEach(form=>{
          const inputs = $$('input, select', form).filter(el=>!el.hidden && el.type !== 'hidden');
          const editBtn = form.querySelector('[data-director-edit]');
          const deleteBtn = form.querySelector('[data-director-delete]');
          const saveBtn = form.querySelector('[data-director-save]');
          const cancelBtn = form.querySelector('[data-director-cancel]');
          inputs.forEach(inp=>{
            inp.dataset.initialValue = inp.value;
            inp.disabled = true;
          });
          function setEditing(state){
            inputs.forEach(inp=>{
              inp.disabled = !state;
              if(!state){
                inp.value = inp.dataset.initialValue || inp.value;
              }
            });
            if(editBtn){ editBtn.hidden = state; }
            if(deleteBtn){ deleteBtn.hidden = state; }
            if(saveBtn){ saveBtn.hidden = !state; }
            if(cancelBtn){ cancelBtn.hidden = !state; }
            if(state && inputs[0]){ inputs[0].focus(); }
          }
          if(editBtn){
            editBtn.addEventListener('click', ()=>setEditing(true));
          }
          if(cancelBtn){
            cancelBtn.addEventListener('click', ()=>setEditing(false));
          }
          form.addEventListener('submit', ()=>{
            inputs.forEach(inp=>{
              inp.disabled = false;
              inp.dataset.initialValue = inp.value;
            });
          });
        });
      }
      if(assignNoteCancel){
        assignNoteCancel.addEventListener('click', ()=>{
          closeAssignNoteModal();
        });
      }
      if(assignNoteCloseTriggers.length){
        assignNoteCloseTriggers.forEach(trigger=>{
          trigger.addEventListener('click', ()=>closeAssignNoteModal());
        });
      }

      if(assignCancelBtn){
        assignCancelBtn.addEventListener('click', ()=>{
          setAssignMode(false);
        });
      }
      if(assignSendBtn){
        assignSendBtn.addEventListener('click', ()=>{
          if(!assignMode){ return; }
          if(!activeCoordinatorKey){
            alert('Selecione um coordenador/curso no filtro.');
            if(directorSelect){ directorSelect.focus(); }
            return;
          }
          if(assignSelected.size === 0){
            alert('Escolha ao menos um colaborador.');
            return;
          }
          if(!assignForm || !assignFieldCoordinator || !assignFieldRows){
            return;
          }
          assignFieldCoordinator.value = activeCoordinatorKey;
          assignFieldRows.value = Array.from(assignSelected).join(',');
          openAssignNoteModal();
        });
      }

      assignCheckboxes.forEach(cb=>{
        cb.addEventListener('change', ()=>{
          if(cb.disabled){
            cb.checked = false;
            return;
          }
          if(!assignMode){
            cb.checked = false;
            return;
          }
          const id = cb.value;
          if(cb.checked){
            assignSelected.add(id);
          }else{
            assignSelected.delete(id);
          }
          const row = cb.closest('tr');
          if(row){
            row.classList.toggle('apf-row-selected', cb.checked);
          }
          updateAssignCount();
          updateAssignHelper();
          updateAssignActions();
        });
      });

      updateAssignCount();
      updateAssignHelper();
      updateAssignActions();
      setActiveCoordinatorKey(activeCoordinatorKey);

      // Scheduler (finance calendar)
      const schedulerEl = $('#apfScheduler');
      const schedulerForm = $('#apfSchedulerForm');
      const schedulerDateInput = $('#apfSchedulerDate');
      const schedulerSelectedDisplay = $('#apfSchedulerSelectedDisplay');
      const schedulerTitleInput = $('#apfSchedulerTitleInput');
      const schedulerSearch = $('#apfSchedulerSearch');
      const schedulerProvidersBox = document.querySelector('.apf-scheduler__providers');
      const schedulerRecipientsHolder = $('#apfSchedulerRecipientsHolder');
      const schedulerTabs = $$('.apf-scheduler__tab');
      const schedulerPickerLabel = $('#apfSchedulerPickerLabel');
      const schedulerModal = $('#apfSchedulerModal');
      const schedulerModalListView = schedulerModal ? schedulerModal.querySelector('[data-view="list"]') : null;
      const schedulerModalEditView = schedulerModal ? schedulerModal.querySelector('[data-view="edit"]') : null;
      const schedulerModalItems = schedulerModal ? schedulerModal.querySelector('.apf-scheduler-modal__items') : null;
      const schedulerModalEmpty = schedulerModal ? schedulerModal.querySelector('.apf-scheduler-modal__empty') : null;
      const schedulerModalCloseTriggers = schedulerModal ? $$('[data-modal-close]', schedulerModal) : [];
      const schedulerModalNewButton = schedulerModal ? schedulerModal.querySelector('[data-modal-new]') : null;
      const schedulerDeleteForm = $('#apfSchedulerDeleteForm');
      const editForm = $('#apfSchedulerEditForm');
      const editTitleInput = $('#apfSchedulerEditTitle');
      const editDateDisplay = $('#apfSchedulerEditDate');
      const editRecipientsHolder = $('#apfSchedulerEditRecipientsHolder');
      const editChips = $('#apfSchedulerEditChips');
      const editSearch = $('#apfSchedulerEditSearch');
      const editProvidersBox = schedulerModal ? schedulerModal.querySelector('.apf-scheduler-edit__providers') : null;
      const editTabs = $$('.apf-scheduler-edit__tab', schedulerModal);
      const MONTH_NAMES = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
      const WEEKDAYS = ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];
      const groupLabels = {
        providers: 'Colaboradores',
        coordinators: 'Coordenadores',
      };

      if(schedulerEl && schedulerForm && schedulerProvidersBox){
        let state = {
          month: new Date(),
          selectedDate: '',
          selectedProviders: new Set(),
          events: [],
          providerGroups: {
            providers: [],
            coordinators: [],
          },
          activeGroup: 'providers',
          providerIndex: new Map(),
          eventsByDate: new Map(),
          eventsByDateDetail: new Map(),
        };
        const modalState = {
          date: '',
          events: [],
          editing: null,
          group: 'providers',
        };
        const editState = {
          activeGroup: 'providers',
          selected: new Map(),
          lockedGroup: '',
        };
        let schedulerModalLastFocus = null;

        state.month.setDate(1);

        function normalizeGroups(groups){
          if(!Array.isArray(groups)){
            groups = [];
          }
          const normalized = [];
          groups.forEach(group=>{
            if(!group){ return; }
            const key = group.toString().toLowerCase();
            if(key === 'providers' || key === 'coordinators'){
              if(!normalized.includes(key)){
                normalized.push(key);
              }
            }
          });
          if(!normalized.length){
            normalized.push('providers');
          }
          return normalized;
        }

        function buildDetailEvent(evt){
          if(!evt){ return null; }
          const normalizedGroups = normalizeGroups(evt.groups);
          const recipientsByGroup = {
            providers: [],
            coordinators: [],
          };
          if(Array.isArray(evt.recipients)){
            evt.recipients.forEach(rec=>{
              if(!rec){ return; }
              const group = (rec.group === 'coordinators') ? 'coordinators' : 'providers';
              const display = (rec.display || rec.name || rec.email || '').toString().trim();
              if(display){
                recipientsByGroup[group].push(display);
              }
            });
          } else if(Array.isArray(evt.recipients_text)){
            const fallback = evt.recipients_text
              .map(item => (item || '').toString().trim())
              .filter(Boolean);
            normalizedGroups.forEach(group=>{
              if(!recipientsByGroup[group].length){
                recipientsByGroup[group] = fallback.slice(0, 50);
              }
            });
          }
          return {
            id: evt.id,
            date: evt.date,
            title: evt.title || '',
            groups: normalizedGroups,
            recipients: recipientsByGroup,
            rawRecipients: Array.isArray(evt.recipients) ? evt.recipients : [],
          };
        }

        function rebuildEventsIndex(){
          state.eventsByDate = new Map();
          state.eventsByDateDetail = new Map();
          const activeGroup = (state.activeGroup || 'providers').toLowerCase();
          state.events.forEach(evt=>{
            if(!evt || !evt.date){ return; }
            const detailEvent = buildDetailEvent(evt);
            if(!detailEvent){ return; }
            const normalizedGroups = detailEvent.groups;
            const date = evt.date;
            if(!state.eventsByDateDetail.has(date)){
              state.eventsByDateDetail.set(date, {
                providers: [],
                coordinators: [],
              });
            }
            const detailEntry = state.eventsByDateDetail.get(date);
            normalizedGroups.forEach(group=>{
              detailEntry[group].push(detailEvent);
            });

            if(activeGroup && normalizedGroups.indexOf(activeGroup) === -1){
              return;
            }
            if(!state.eventsByDate.has(date)){
              state.eventsByDate.set(date, []);
            }
            state.eventsByDate.get(date).push(evt);
          });
        }

        function getEventsForGroup(date, group){
          const normalizedGroup = (group === 'coordinators') ? 'coordinators' : 'providers';
          const entry = state.eventsByDateDetail.get(date);
          if(entry && Array.isArray(entry[normalizedGroup]) && entry[normalizedGroup].length){
            return entry[normalizedGroup].slice();
          }
          const fallback = [];
          state.events.forEach(evt=>{
            if(!evt || evt.date !== date){ return; }
            const detailEvent = buildDetailEvent(evt);
            if(!detailEvent){ return; }
            if(detailEvent.groups.indexOf(normalizedGroup) === -1){
              return;
            }
            fallback.push(detailEvent);
          });
          if(fallback.length){
            let target = entry;
            if(!target){
              target = { providers: [], coordinators: [] };
              state.eventsByDateDetail.set(date, target);
            }
            target[normalizedGroup] = fallback.slice();
          }
          return fallback;
        }

        try{
          const rawEvents = JSON.parse(schedulerEl.getAttribute('data-events') || '[]');
          if(Array.isArray(rawEvents)){
            state.events = rawEvents
              .map(evt=>{
                if(!evt || !evt.date){ return null; }
                const clone = Object.assign({}, evt);
                clone.date = (clone.date || '').toString();
                clone.title = clone.title || '';
                clone.groups = normalizeGroups(clone.groups);
                clone.recipients = Array.isArray(clone.recipients) ? clone.recipients : [];
                clone.recipients_text = Array.isArray(clone.recipients_text) ? clone.recipients_text : [];
                return clone;
              })
              .filter(Boolean);
          }
        }catch(_e){}
        try{
          const rawProviders = JSON.parse(schedulerEl.getAttribute('data-providers') || '[]');
          if(Array.isArray(rawProviders)){
            state.providerGroups.providers = rawProviders;
          }else if(rawProviders && typeof rawProviders === 'object'){
            if(Array.isArray(rawProviders.providers)){ state.providerGroups.providers = rawProviders.providers; }
            if(Array.isArray(rawProviders.coordinators)){ state.providerGroups.coordinators = rawProviders.coordinators; }
          }
        }catch(_e){}
        rebuildEventsIndex();

        function rebuildProviderIndex(){
          state.providerIndex.clear();
          ['providers','coordinators'].forEach(group=>{
            const list = state.providerGroups[group] || [];
            list.forEach(provider=>{
              if(provider && provider.key){
                provider.group = provider.group || group;
                const token = providerToken(provider, provider.group);
                provider.token = token;
                state.providerIndex.set(token, provider);
              }
            });
          });
        }

        function formatDateBr(value){
          if(!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)){ return value || '—'; }
          return value.slice(8,10) + '/' + value.slice(5,7) + '/' + value.slice(0,4);
        }

        function updateHiddenRecipients(){
          if(!schedulerRecipientsHolder){ return; }
          schedulerRecipientsHolder.innerHTML = '';
          state.selectedProviders.forEach(token=>{
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'apf_scheduler_recipients[]';
            input.value = token;
            schedulerRecipientsHolder.appendChild(input);
          });
        }

        function toggleProvider(token){
          if(!state.providerIndex.has(token)){ return; }
          if(state.selectedProviders.has(token)){
            state.selectedProviders.delete(token);
          }else{
            state.selectedProviders.add(token);
          }
          updateHiddenRecipients();
          renderProviders((schedulerSearch && schedulerSearch.value) || '');
        }

        function updateAudienceCopy(){
          const label = state.activeGroup === 'coordinators' ? 'Buscar coordenadores' : 'Buscar colaboradores';
          if(schedulerPickerLabel){
            schedulerPickerLabel.textContent = label;
          }
          if(schedulerSearch){
            schedulerSearch.placeholder = state.activeGroup === 'coordinators'
              ? 'Digite nome ou e-mail do coordenador'
              : 'Digite nome ou e-mail';
          }
        }

        function emptyMessage(){
          return state.activeGroup === 'coordinators'
            ? 'Nenhum coordenador encontrado.'
            : 'Nenhum colaborador encontrado.';
        }

        function renderProviders(filter){
          if(!schedulerProvidersBox){ return; }
          const term = (filter || '').toLowerCase();
          schedulerProvidersBox.innerHTML = '';
          const source = state.providerGroups[state.activeGroup] || [];
          const filtered = source.filter(provider=>{
            const blob = (provider.label + ' ' + (provider.email || '')).toLowerCase();
            return term ? blob.includes(term) : true;
          });
          if(!filtered.length){
            const emptyMsg = document.createElement('p');
            emptyMsg.className = 'apf-scheduler__providers-empty';
            emptyMsg.textContent = emptyMessage();
            schedulerProvidersBox.appendChild(emptyMsg);
            return;
          }
          filtered.forEach(provider=>{
            if(!provider || !provider.key){ return; }
            const token = provider.token || providerToken(provider, provider.group || state.activeGroup);
            const label = document.createElement('label');
            label.className = 'apf-scheduler__provider apf-scheduler__provider--' + (provider.group || state.activeGroup || 'providers');
            if(state.selectedProviders.has(token)){
              label.classList.add('is-selected');
            }
            label.setAttribute('data-token', token);

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = state.selectedProviders.has(token);
            checkbox.tabIndex = -1;

            const span = document.createElement('span');
            const displayName = provider.name
              || ((provider.label || '').split('<')[0].trim())
              || provider.email
              || '';
            span.textContent = displayName;

            label.appendChild(checkbox);
            label.appendChild(span);
            label.addEventListener('click', function(e){
              e.preventDefault();
              toggleProvider(token);
            });
            schedulerProvidersBox.appendChild(label);
          });
        }

        function setActiveGroup(group){
          if(!state.providerGroups[group]){ return; }
          state.activeGroup = group;
          rebuildProviderIndex();
          rebuildEventsIndex();
          if(schedulerSearch){ schedulerSearch.value = ''; }
          updateAudienceCopy();
          renderProviders('');
          renderCalendar();
          schedulerTabs.forEach(tab=>{
            const key = tab.getAttribute('data-scheduler-group');
            tab.classList.toggle('is-active', key === group);
          });
        }

        function initTabs(){
          schedulerTabs.forEach(tab=>{
            const group = tab.getAttribute('data-scheduler-group');
            const hasEntries = (state.providerGroups[group] || []).length > 0;
            tab.classList.toggle('is-disabled', !hasEntries);
            tab.removeAttribute('aria-disabled');
            tab.addEventListener('click', ()=>{
              setActiveGroup(group);
            });
          });
          let initialGroup = 'providers';
          if(!(state.providerGroups.providers || []).length && (state.providerGroups.coordinators || []).length){
            initialGroup = 'coordinators';
          }
          setActiveGroup(initialGroup);
        }

        function setSelectedDate(iso, openModal = false){
          state.selectedDate = iso;
          if(schedulerDateInput){
            schedulerDateInput.value = iso;
          }
          if(schedulerSelectedDisplay){
            schedulerSelectedDisplay.textContent = iso ? formatDateBr(iso) : 'Nenhum dia selecionado';
          }
          renderCalendar();
          if(openModal && schedulerModal && iso){
            const groupKey = (state.activeGroup || 'providers').toLowerCase();
            const preview = getEventsForGroup(iso, groupKey);
            if(preview.length){
              openSchedulerModal(iso, preview);
            }
          }
        }

        function changeMonth(delta){
          state.month.setMonth(state.month.getMonth() + delta, 1);
          renderCalendar();
        }

        function renderCalendar(){
          const container = document.createElement('div');
          container.className = 'apf-scheduler__calendar-inner';

          const header = document.createElement('div');
          header.className = 'apf-scheduler__calendar-header';

          const title = document.createElement('h3');
          const monthIndex = state.month.getMonth();
          const year = state.month.getFullYear();
          title.textContent = MONTH_NAMES[monthIndex] + ' ' + year;

          const nav = document.createElement('div');
          nav.className = 'apf-scheduler__calendar-nav';
          const prevBtn = document.createElement('button');
          prevBtn.type = 'button';
          prevBtn.className = 'apf-scheduler__nav-btn';
          prevBtn.innerHTML = '&larr;';
          prevBtn.addEventListener('click', ()=>changeMonth(-1));

          const nextBtn = document.createElement('button');
          nextBtn.type = 'button';
          nextBtn.className = 'apf-scheduler__nav-btn';
          nextBtn.innerHTML = '&rarr;';
          nextBtn.addEventListener('click', ()=>changeMonth(1));

          nav.appendChild(prevBtn);
          nav.appendChild(nextBtn);
          header.appendChild(title);
          header.appendChild(nav);

          const weekdaysRow = document.createElement('div');
          weekdaysRow.className = 'apf-scheduler__weekdays';
          WEEKDAYS.forEach(day=>{
            const span = document.createElement('span');
            span.className = 'apf-scheduler__weekday';
            span.textContent = day;
            weekdaysRow.appendChild(span);
          });

          const daysGrid = document.createElement('div');
          daysGrid.className = 'apf-scheduler__days';

          const firstWeekday = (state.month.getDay() + 6) % 7;
          const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
          const totalCells = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;

          for(let cell = 0; cell < totalCells; cell++){
            const dayNumber = cell - firstWeekday + 1;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'apf-scheduler__day';

            if(dayNumber < 1 || dayNumber > daysInMonth){
              button.disabled = true;
              button.textContent = '';
            }else{
              const iso = year + '-' + String(monthIndex + 1).padStart(2,'0') + '-' + String(dayNumber).padStart(2,'0');
              button.textContent = String(dayNumber);
              if(state.selectedDate === iso){
                button.classList.add('apf-scheduler__day--selected');
              }
              if(state.eventsByDate.has(iso)){
                const marker = document.createElement('span');
                const markerGroup = (state.activeGroup || 'providers').toLowerCase();
                marker.className = 'apf-scheduler__day-marker apf-scheduler__day-marker--' + markerGroup;
                button.appendChild(marker);
              }
              const detailGroup = (state.activeGroup || 'providers').toLowerCase();
              const detailEntry = state.eventsByDateDetail.get(iso);
              let detailEvents = detailEntry && Array.isArray(detailEntry[detailGroup]) ? detailEntry[detailGroup] : [];
              if(!detailEvents.length){
                detailEvents = getEventsForGroup(iso, detailGroup);
              }
              if(detailEvents.length){
                button.title = detailEvents.map(evt=>evt.title || 'Aviso').join(', ');
              }else{
                button.removeAttribute('title');
              }
              button.addEventListener('click', ()=>setSelectedDate(iso, true));
            }

            daysGrid.appendChild(button);
          }

          container.appendChild(header);
          container.appendChild(weekdaysRow);
          container.appendChild(daysGrid);
          schedulerEl.innerHTML = '';
          schedulerEl.appendChild(container);
        }

        function openSchedulerModal(date, preloadedEvents){
          if(!schedulerModal){ return; }
          const activeGroup = (state.activeGroup || 'providers').toLowerCase();
          const eventsForGroup = Array.isArray(preloadedEvents) && preloadedEvents.length
            ? preloadedEvents
            : getEventsForGroup(date, activeGroup);
          if(!eventsForGroup.length){
            return;
          }
          modalState.date = date;
          modalState.group = activeGroup;
          modalState.events = eventsForGroup.slice();
          modalState.editing = null;
          exitEditMode(false);
          renderModalList();
          schedulerModal.setAttribute('aria-hidden','false');
          schedulerModalLastFocus = document.activeElement;
          document.body.style.overflow = 'hidden';
          const focusTarget = schedulerModal.querySelector('.apf-scheduler-modal__close') || schedulerModal;
          if(focusTarget){ focusTarget.focus(); }
        }

        function closeSchedulerModal(){
          if(!schedulerModal){ return; }
          schedulerModal.setAttribute('aria-hidden','true');
          document.body.style.overflow = '';
          modalState.date = '';
          modalState.events = [];
          modalState.editing = null;
          exitEditMode(false);
          if(schedulerModalLastFocus){
            schedulerModalLastFocus.focus();
          }
        }

        function recipientsSummary(evt, group){
          if(!evt || !group){ return ''; }
          if(evt.recipients && Array.isArray(evt.recipients[group]) && evt.recipients[group].length){
            return evt.recipients[group].join('; ');
          }
          return '';
        }

        function renderModalList(){
          if(!schedulerModalItems || !schedulerModalEmpty){ return; }
          schedulerModalItems.innerHTML = '';
          const items = Array.isArray(modalState.events) ? modalState.events.slice() : [];
          const activeGroup = modalState.group || 'providers';
          items.sort((a,b)=>{
            const aTitle = (a && a.title) ? a.title.toString().toLowerCase() : '';
            const bTitle = (b && b.title) ? b.title.toString().toLowerCase() : '';
            return aTitle.localeCompare(bTitle);
          });
          if(!items.length){
            schedulerModalEmpty.hidden = false;
            return;
          }
          schedulerModalEmpty.hidden = true;
          items.forEach(evt=>{
            if(!evt){ return; }
            const card = document.createElement('article');
            card.className = 'apf-scheduler-modal__event';

            const heading = document.createElement('h4');
            heading.textContent = evt.title || 'Aviso sem título';

            const meta = document.createElement('div');
            meta.className = 'apf-scheduler-modal__event-meta';
            const dateBadge = document.createElement('span');
            dateBadge.textContent = 'Data: ' + (evt.date ? formatDateBr(evt.date) : (modalState.date ? formatDateBr(modalState.date) : '—'));
            meta.appendChild(dateBadge);
            const audience = document.createElement('span');
            audience.textContent = 'Público: ' + (groupLabels[activeGroup] || activeGroup);
            meta.appendChild(audience);

            const actions = document.createElement('div');
            actions.className = 'apf-scheduler-modal__event-actions';

            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'apf-btn apf-btn--primary';
            editBtn.textContent = 'Editar';
            editBtn.addEventListener('click', ()=>enterEditMode(evt, activeGroup));

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'apf-btn apf-btn--ghost';
            removeBtn.textContent = 'Remover';
            removeBtn.addEventListener('click', ()=>confirmDelete(evt));

            actions.appendChild(editBtn);
            actions.appendChild(removeBtn);

            card.appendChild(heading);
            card.appendChild(meta);
            card.appendChild(actions);
            schedulerModalItems.appendChild(card);
          });
        }

        function confirmDelete(evt){
          if(!schedulerDeleteForm || !evt || !evt.id){ return; }
          const targetInput = schedulerDeleteForm.querySelector('input[name="apf_scheduler_event"]');
          if(!targetInput){ return; }
          const message = evt.title ? ('Remover o aviso "' + evt.title + '"?') : 'Remover este aviso?';
          if(!window.confirm(message)){
            return;
          }
          targetInput.value = evt.id;
          schedulerDeleteForm.submit();
        }

        function enterEditMode(evt, lockedGroup, mode = 'update'){
          if(!schedulerModalEditView || !schedulerModalListView || !editForm){ return; }
          const enforcedGroup = (lockedGroup || state.activeGroup || 'providers').toLowerCase();
          editState.lockedGroup = enforcedGroup;
          editState.activeGroup = enforcedGroup;
          updateEditTabsState();
          setEditActiveGroup(enforcedGroup, true);
          modalState.editing = mode === 'update' ? evt : null;
          schedulerModalListView.hidden = true;
          schedulerModalEditView.hidden = false;
          const actionField = editForm.querySelector('input[name="apf_scheduler_action"]');
          if(actionField){
            actionField.value = mode === 'add' ? 'add' : 'update';
          }
          const eventField = editForm.querySelector('input[name="apf_scheduler_event"]');
          if(eventField){ eventField.value = evt && evt.id ? evt.id : ''; }
          const dateField = editForm.querySelector('input[name="apf_scheduler_date"]');
          if(dateField){ dateField.value = evt && evt.date ? evt.date : ''; }
          if(editDateDisplay){ editDateDisplay.textContent = evt && evt.date ? formatDateBr(evt.date) : '—'; }
          if(editTitleInput){ editTitleInput.value = evt && evt.title ? evt.title : ''; }
          editState.selected = new Map();
          const recipients = (evt && Array.isArray(evt.rawRecipients)) ? evt.rawRecipients : [];
          recipients.forEach(rec=>{
            if(!rec){ return; }
            const group = (rec.group === 'coordinators') ? 'coordinators' : 'providers';
            if(group !== enforcedGroup){ return; }
            const key = rec.key ? rec.key.toString() : '';
            if(!key){ return; }
            const token = providerToken({ key, group }, group);
            let provider = state.providerIndex.get(token);
            if(!provider){
              provider = {
                key,
                group,
                name: rec.name || '',
                email: rec.email || '',
                label: rec.display || rec.name || rec.email || '',
                director_key: rec.director_key || '',
                director_name: rec.director_name || '',
                course: rec.course || '',
              };
              state.providerIndex.set(token, provider);
            }
            if(provider){
              editState.selected.set(token, provider);
            }
          });
          syncEditRecipients();
          if(editTitleInput){
            editTitleInput.focus();
          }
        }

        function startCreateMode(){
          if(!schedulerModal || !editForm){ return; }
          const targetDate = modalState.date || state.selectedDate || '';
          if(!targetDate){
            return;
          }
          const group = (modalState.group || state.activeGroup || 'providers').toLowerCase();
          const template = {
            id: '',
            date: targetDate,
            title: '',
            rawRecipients: [],
          };
          enterEditMode(template, group, 'add');
          if(editTitleInput){
            editTitleInput.value = '';
          }
        }

        function exitEditMode(renderList = true){
          if(!schedulerModalEditView || !schedulerModalListView){ return; }
          schedulerModalEditView.hidden = true;
          schedulerModalListView.hidden = false;
          modalState.editing = null;
          editState.lockedGroup = '';
          editState.activeGroup = state.activeGroup || 'providers';
          updateEditTabsState();
          editState.selected.clear();
          if(editForm){
            const nonceField = editForm.querySelector('input[name="apf_scheduler_nonce_edit"]');
            const nonceValue = nonceField ? nonceField.value : '';
            editForm.reset();
            if(nonceField && nonceValue){
              nonceField.value = nonceValue;
            }
            const actionField = editForm.querySelector('input[name="apf_scheduler_action"]');
            if(actionField){
              actionField.value = 'update';
            }
          }
          if(editChips){
            editChips.innerHTML = '<p class="apf-scheduler-edit__chips-empty">Nenhum destinatário selecionado.</p>';
          }
          if(editRecipientsHolder){
            editRecipientsHolder.innerHTML = '';
          }
          setEditActiveGroup(editState.activeGroup || 'providers', true);
          if(renderList){
            renderModalList();
          }
        }

        function syncEditRecipients(){
          if(!editRecipientsHolder){ return; }
          editRecipientsHolder.innerHTML = '';
          editState.selected.forEach((provider, token)=>{
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'apf_scheduler_recipients[]';
            input.value = token;
            editRecipientsHolder.appendChild(input);
          });
          renderEditChips();
        }

        function renderEditChips(){
          if(!editChips){ return; }
          editChips.innerHTML = '';
          if(!editState.selected.size){
            const emptyMsg = document.createElement('p');
            emptyMsg.className = 'apf-scheduler-edit__chips-empty';
            emptyMsg.textContent = 'Nenhum destinatário selecionado.';
            editChips.appendChild(emptyMsg);
            return;
          }
          editState.selected.forEach((provider, token)=>{
            const chip = document.createElement('span');
            chip.className = 'apf-scheduler-edit__chip';
            const label = provider.name
              || ((provider.label || '').split('<')[0].trim())
              || provider.email
              || token;
            chip.textContent = label;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('aria-label', 'Remover ' + label);
            btn.innerHTML = '&times;';
            btn.addEventListener('click', ()=>{
              editState.selected.delete(token);
              syncEditRecipients();
              renderEditProvidersList(editSearch ? editSearch.value : '');
            });
            chip.appendChild(btn);
            editChips.appendChild(chip);
          });
        }

        function updateEditTabsState(){
          if(!editTabs.length){ return; }
          editTabs.forEach(tab=>{
            const tabGroup = tab.getAttribute('data-edit-group') || 'providers';
            const disabled = !!(editState.lockedGroup && tabGroup !== editState.lockedGroup);
            tab.classList.toggle('is-disabled', disabled);
            tab.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            tab.disabled = disabled;
          });
        }

        function setEditActiveGroup(group, skipSearchReset = false){
          const normalized = (group === 'coordinators') ? 'coordinators' : 'providers';
          if(editState.lockedGroup && normalized !== editState.lockedGroup){
            return;
          }
          editState.activeGroup = normalized;
          editTabs.forEach(tab=>{
            const key = tab.getAttribute('data-edit-group');
            tab.classList.toggle('is-active', key === normalized);
          });
          updateEditSearchPlaceholder();
          if(!skipSearchReset && editSearch){
            editSearch.value = '';
          }
          renderEditProvidersList(editSearch ? editSearch.value : '');
        }

        function updateEditSearchPlaceholder(){
          if(!editSearch){ return; }
          editSearch.placeholder = (editState.activeGroup === 'coordinators')
            ? 'Digite nome ou e-mail do coordenador'
            : 'Digite nome ou e-mail do colaborador';
        }

        function renderEditProvidersList(filter){
          if(!editProvidersBox){ return; }
          const term = (filter || '').toLowerCase();
          const group = editState.lockedGroup || editState.activeGroup || 'providers';
          editState.activeGroup = group;
          editProvidersBox.innerHTML = '';
          const list = state.providerGroups[group] || [];
          const results = list.filter(provider=>{
            const blob = (provider.label + ' ' + (provider.email || '')).toLowerCase();
            return term ? blob.includes(term) : true;
          });
          if(!results.length){
            const emptyMsg = document.createElement('p');
            emptyMsg.className = 'apf-scheduler-edit__providers-empty';
            emptyMsg.textContent = group === 'coordinators'
              ? 'Nenhum coordenador encontrado.'
              : 'Nenhum colaborador encontrado.';
            editProvidersBox.appendChild(emptyMsg);
            return;
          }
          results.slice(0,25).forEach(provider=>{
            if(!provider || !provider.key){ return; }
            const token = provider.token || providerToken(provider, provider.group || group);
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'apf-scheduler-edit__provider';
            if(editState.selected.has(token)){
              row.classList.add('is-selected');
            }
          const label = document.createElement('span');
          const displayName = provider.name
            || ((provider.label || '').split('<')[0].trim())
            || provider.email
            || '';
          label.textContent = displayName;
          label.style.textAlign = 'left';
          label.style.flex = '1 1 auto';
          label.style.minWidth = '0';
          const hint = document.createElement('small');
          hint.textContent = provider.email || '';
          hint.style.color = '#475467';
          hint.style.whiteSpace = 'nowrap';
          row.appendChild(label);
          if(provider.email){
            row.appendChild(hint);
          }
            row.addEventListener('click', ()=>{
              toggleEditRecipient(token);
            });
            editProvidersBox.appendChild(row);
          });
        }

        function toggleEditRecipient(token){
          if(!token){ return; }
          if(editState.selected.has(token)){
            editState.selected.delete(token);
          }else{
            const provider = state.providerIndex.get(token);
            if(!provider){ return; }
            if(editState.lockedGroup && provider.group && provider.group !== editState.lockedGroup){
              return;
            }
            editState.selected.set(token, provider);
          }
          syncEditRecipients();
          renderEditProvidersList(editSearch ? editSearch.value : '');
        }

        rebuildProviderIndex();
        initTabs();
        setEditActiveGroup(editState.activeGroup || 'providers', true);
        updateEditTabsState();

        if(schedulerModalCloseTriggers.length){
          schedulerModalCloseTriggers.forEach(trigger=>{
            trigger.addEventListener('click', closeSchedulerModal);
          });
        }
        if(schedulerModalNewButton){
          schedulerModalNewButton.addEventListener('click', startCreateMode);
        }
        if(schedulerModal){
          schedulerModal.addEventListener('keydown', function(e){
            if(e.key === 'Escape'){
              e.preventDefault();
              closeSchedulerModal();
            }
          });
        }
        document.addEventListener('keydown', function(e){
          if(e.key === 'Escape' && schedulerModal && schedulerModal.getAttribute('aria-hidden') === 'false'){
            e.preventDefault();
            closeSchedulerModal();
          }
        });
        const editCancelButton = schedulerModal ? schedulerModal.querySelector('[data-edit-cancel]') : null;
        if(editCancelButton){
          editCancelButton.addEventListener('click', ()=>exitEditMode());
        }
        if(editTabs.length){
          editTabs.forEach(tab=>{
            tab.addEventListener('click', ()=>{
              const group = tab.getAttribute('data-edit-group') || 'providers';
              setEditActiveGroup(group);
            });
          });
        }
        if(editSearch){
          editSearch.addEventListener('input', function(){
            renderEditProvidersList(this.value || '');
          });
        }
        if(editForm){
          editForm.addEventListener('submit', function(e){
            if(editState.selected.size === 0){
              e.preventDefault();
              alert('Escolha ao menos um destinatário.');
            }
          });
        }

        if(schedulerSearch){
          schedulerSearch.addEventListener('input', function(){
            renderProviders(this.value || '');
          });
        }

        schedulerForm.addEventListener('submit', function(e){
          if(!state.selectedDate){
            e.preventDefault();
            alert('Selecione um dia no calendário.');
            return;
          }
          if(state.selectedProviders.size === 0){
            e.preventDefault();
            alert('Escolha ao menos um prestador para receber o aviso.');
            return;
          }
          if(schedulerTitleInput && !schedulerTitleInput.value.trim()){
            e.preventDefault();
            alert('Informe o título do aviso.');
            return;
          }
        });
      }

      function highlightRow(row, q){
        $$('.apf-highlight', row).forEach(el=>{ el.outerHTML = el.textContent; });
        if(!q){ return; }
        const qn = norm(q);
        $$('#apfTable tbody td', row).forEach(td=>{
          if(td.classList.contains('apf-actions')) return;
          const base = td.textContent; let html = base;
          if(qn && norm(base).includes(qn)){
            try{
              const esc = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); const re = new RegExp(esc,'ig');
              html = base.replace(re, m=>'<span class="apf-highlight">'+m+'</span>');
            }catch(e){}
          }
          td.innerHTML = html;
        });
      }

      const PAGE_SIZE = 10;
      let currentPage = 0;
      let visibleRows = rows.slice();
      let activeQuery = '';
      let activeFilterKey = '';

      function updatePager(totalVisible){
        if(!pagerPrev || !pagerNext || !pagerLabel){ return; }
        const total = totalVisible || 0;
        const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        if(currentPage >= totalPages){ currentPage = totalPages - 1; }
        if(currentPage < 0){ currentPage = 0; }
        pagerPrev.disabled = total === 0 || currentPage === 0;
        pagerNext.disabled = total === 0 || currentPage >= (totalPages - 1);
        pagerPrev.style.visibility = totalPages > 1 ? 'visible' : 'hidden';
        pagerNext.style.visibility = totalPages > 1 ? 'visible' : 'hidden';
        pagerLabel.textContent = total ? ((currentPage + 1) + '/' + totalPages) : '0/0';
      }

      function applyPagination(){
        const total = visibleRows.length;
        const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        if(currentPage >= totalPages){ currentPage = totalPages - 1; }
        if(currentPage < 0){ currentPage = 0; }
        rows.forEach(row=>{
          row.classList.remove('apf-page-hide');
          const mobile = getMobileRow(row);
          if(mobile){ mobile.classList.remove('apf-page-hide'); }
        });
        visibleRows.forEach((row, idx)=>{
          const hide = idx < (currentPage * PAGE_SIZE) || idx >= ((currentPage + 1) * PAGE_SIZE);
          row.classList.toggle('apf-page-hide', hide);
          const mobile = getMobileRow(row);
          if(mobile){ mobile.classList.toggle('apf-page-hide', hide); }
        });
        updatePager(total);
      }

      function applyFilter(resetPage = true){
        if(resetPage){ currentPage = 0; }
        const qn = norm(activeQuery);
        const qd = digits(activeQuery);
        const filterApplied = !!(activeQuery || activeFilterKey);
        let visible = 0;
        const matches = [];
        rows.forEach(row=>{
          const raw = row.getAttribute('data-search') || row.innerText || '';
          const n = norm(raw), d = digits(raw);
          const okText = qn ? n.includes(qn) : true;
          const okDig  = qd ? d.includes(qd) : true;
          const okDirector = activeFilterKey ? (row.getAttribute('data-director-key') === activeFilterKey) : true;
          const show = okText && okDig && okDirector;
          row.classList.toggle('apf-hide', !show);
          const mobile = getMobileRow(row);
          if(mobile){ mobile.classList.toggle('apf-hide', !show); }
          if(show){
            visible++; matches.push(row); highlightRow(row, activeQuery);
          }else{
            highlightRow(row, '');
          }
        });
        visibleRows = matches;
        applyPagination();
        countEl.textContent = filterApplied ? (visible + ' resultado(s)') : (rows.length + ' registro(s)');
      }

      function runSearch(){
        activeQuery = (input.value || '').trim();
        activeFilterKey = directorSelect ? directorSelect.value : '';
        applyFilter(true);
      }

      function clearFilter(){
        if(assignMode){
          alert('Finalize ou cancele o envio antes de alterar os filtros.');
          return;
        }
        activeQuery = '';
        activeFilterKey = '';
        input.value = '';
        if(directorSelect){ directorSelect.value = ''; }
        setActiveCoordinatorKey('');
        applyFilter(true);
        input.focus();
      }


      // Modal
      const modal = $('#apfModal');
      const details = $('#apfDetails');
      const adminLink = $('#apfAdminLink');
      const pager = $('#apfModalPager');
      const counter = $('#apfModalCounter');
      const navPrev = $('#apfModalPrev');
      const navNext = $('#apfModalNext');
      let lastFocus = null;
      let historyList = [];
      let historyIndex = 0;

      function renderHistory(idx){
        const entry = historyList[idx];
        if(!entry){ return; }
        const data = entry.payload || {};
        details.innerHTML = '';
        Object.keys(data).forEach(k=>{
          if(k[0] === '_') return;
          const dt = document.createElement('dt'); dt.textContent = k;
          const value = (data[k] === undefined || data[k] === null || data[k] === '') ? '—' : data[k];
          const dd = document.createElement('dd'); dd.textContent = value;
          details.appendChild(dt); details.appendChild(dd);
        });
        if(data._admin_url){
          adminLink.href = data._admin_url;
          adminLink.style.display = '';
        }else{
          adminLink.style.display = 'none';
        }
        if(counter){
          let label = 'Solicitação ' + (idx + 1) + ' de ' + historyList.length;
          if(idx === 0){
            label += ' - mais recente';
          } else if(idx === historyList.length - 1){
            label += ' - mais antiga';
          }
          counter.textContent = label;
        }
        if(navPrev){
          const showPrev = idx > 0;
          navPrev.style.display = showPrev ? 'flex' : 'none';
          navPrev.disabled = !showPrev;
        }
        if(navNext){
          const showNext = idx < (historyList.length - 1);
          navNext.style.display = showNext ? 'flex' : 'none';
          navNext.disabled = !showNext;
        }
      }

      function openModal(history){
        historyList = Array.isArray(history) ? history : [];
        if(!historyList.length){
          return;
        }
        historyIndex = 0;
        renderHistory(historyIndex);
        modal.setAttribute('aria-hidden','false');
        lastFocus = document.activeElement;
        const focusTarget = modal.querySelector('.apf-modal__close') || modal;
        focusTarget.focus();
        document.body.style.overflow = 'hidden';
      }
      function closeModal(){
        modal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        if(lastFocus){ lastFocus.focus(); }
      }

      if(navPrev){
        navPrev.addEventListener('click', function(){
          if(historyIndex > 0){
            historyIndex -= 1;
            renderHistory(historyIndex);
          }
        });
      }
      if(navNext){
        navNext.addEventListener('click', function(){
          if(historyIndex < historyList.length - 1){
            historyIndex += 1;
            renderHistory(historyIndex);
          }
        });
      }

      const mobileRows = document.querySelectorAll('.apf-row-mobile');
      mobileRows.forEach(row=>{
        const toggle = row.querySelector('.apf-row-mobile__toggle');
        const panel = row.querySelector('.apf-row-mobile__panel');
        if(!toggle || !panel){ return; }
        // Garante estado fechado inicial
        toggle.setAttribute('aria-expanded','false');
        panel.hidden = true;
        row.classList.remove('is-open');
        toggle.addEventListener('click', ()=>{
          const expanded = toggle.getAttribute('aria-expanded') === 'true';
          const nextState = !expanded;
          toggle.setAttribute('aria-expanded', nextState ? 'true' : 'false');
          panel.hidden = !nextState;
          row.classList.toggle('is-open', nextState);
        });
      });

      document.addEventListener('click', (e)=>{
        const btn = e.target.closest('.apf-btn-details');
        if(btn){
          const tr = btn.closest('tr');
          let history = [];
          const historyAttr = tr.getAttribute('data-history');
          if(historyAttr){
            try{
              history = JSON.parse(historyAttr);
            }catch(_e){}
          }
          if(!Array.isArray(history) || !history.length){
            let single = {};
            try{
              single = JSON.parse(tr.getAttribute('data-json') || '{}');
            }catch(_e){}
            if(single && Object.keys(single).length){
              history = [{ payload: single, order: 1, total: 1 }];
            }
          }
          if(Array.isArray(history) && history.length){
            openModal(history);
          }
        }
        if(e.target.matches('[data-close]')) closeModal();
      });
      document.addEventListener('keydown', (e)=>{
        if(e.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false'){ closeModal(); }
      });

      // Retorno dos coordenadores
      const coordReturnModal = $('#apfCoordReturnModal');
      const coordReturnDialog = coordReturnModal ? coordReturnModal.querySelector('.apf-coord-return-modal__dialog') : null;
      const coordReturnBody = $('#apfCoordModalBody');
      const coordReturnTitle = $('#apfCoordModalTitle');
      const coordReturnSubtitle = $('#apfCoordModalSubtitle');
      const coordReturnCounts = $('#apfCoordModalCounts');
      const coordReturnCountApproved = coordReturnCounts ? coordReturnCounts.querySelector('[data-count-approved]') : null;
      const coordReturnCountRejected = coordReturnCounts ? coordReturnCounts.querySelector('[data-count-rejected]') : null;
      const coordReturnCloseTriggers = coordReturnModal ? coordReturnModal.querySelectorAll('[data-coord-return-close]') : [];
      const coordReturnSection = document.getElementById('apfCoordReturn');
      const coordReturnGrid = coordReturnSection ? coordReturnSection.querySelector('.apf-coord-return__grid') : null;
      let coordReturnCards = coordReturnGrid ? Array.from(coordReturnGrid.querySelectorAll('.apf-coord-return__card')) : [];
      const coordReturnMonthSelect = document.getElementById('apfCoordReturnMonth');
      const coordReturnPagerPrev = document.getElementById('apfCoordReturnPrev');
      const coordReturnPagerNext = document.getElementById('apfCoordReturnNext');
      const coordReturnPagerLabel = document.getElementById('apfCoordReturnLabel');
      const coordReturnEmptyState = document.getElementById('apfCoordReturnEmpty');
      const coordArchiveToggle = coordReturnSection ? coordReturnSection.querySelector('.apf-coord-return__archive-icon-btn') : null;
      const coordArchiveModal = $('#apfCoordArchiveModal');
      const coordArchiveDialog = coordArchiveModal ? coordArchiveModal.querySelector('.apf-coord-archive-modal__dialog') : null;
      const coordArchiveBody = $('#apfCoordArchiveBody');
      const coordArchiveCloseTriggers = coordArchiveModal ? coordArchiveModal.querySelectorAll('[data-coord-archive-close]') : [];
      const coordArchiveTrigger = document.querySelector('[data-coord-archive]');
      const coordReturnGroupsMap = new Map();
      let coordReturnLastFocus = null;
      let coordArchiveLastFocus = null;
      const coordFaepaBox = $('#apfCoordFaepaBox');
      const coordFaepaStatus = $('#apfCoordFaepaStatus');
      const coordFaepaForm = $('#apfCoordFaepaForm');
      const coordFaepaBatch = coordFaepaForm ? coordFaepaForm.querySelector('input[name=\"apf_faepa_batch_id\"]') : null;
      const coordFaepaNote = $('#apfCoordFaepaNote');
      const coordFaepaSubmit = $('#apfCoordFaepaSubmit');
      const coordFaepaHint = coordFaepaBox ? coordFaepaBox.querySelector('[data-faepa-hint]') : null;

      const coordReturnDataNode = document.getElementById('apfCoordReturnData');
      if(coordReturnDataNode){
        try{
          const parsed = JSON.parse(coordReturnDataNode.textContent || '[]');
          if(Array.isArray(parsed)){
            parsed.forEach(group=>{
              if(group && group.id){
                coordReturnGroupsMap.set(group.id, group);
              }
            });
          }
        }catch(err){
          if(window.console){
            console.error('Não foi possível carregar os retornos dos coordenadores.', err);
          }
        }
      }

      const COORD_RETURN_PAGE_SIZE = 5;
      let coordReturnPage = 0;
      let coordReturnFiltered = coordReturnCards.slice();

      function renderCoordReturnGrid(resetPage = false){
        if(!coordReturnGrid){ return; }
        if(resetPage){ coordReturnPage = 0; }
        const monthFilter = coordReturnMonthSelect ? coordReturnMonthSelect.value : '';
        coordReturnFiltered = [];
        coordReturnCards.forEach(card=>{
          const cardMonth = card.getAttribute('data-coord-month') || '';
          const matches = !monthFilter || cardMonth === monthFilter;
          if(matches){
            coordReturnFiltered.push(card);
          }
        });
        const total = coordReturnFiltered.length;
        const totalPages = total > 0 ? Math.ceil(total / COORD_RETURN_PAGE_SIZE) : 0;
        if(coordReturnPage >= totalPages){ coordReturnPage = Math.max(0, totalPages - 1); }
        coordReturnCards.forEach(card=>card.classList.add('apf-coord-return__hidden'));
        coordReturnFiltered.forEach((card, idx)=>{
          const hide = totalPages === 0 || idx < (coordReturnPage * COORD_RETURN_PAGE_SIZE) || idx >= ((coordReturnPage + 1) * COORD_RETURN_PAGE_SIZE);
          card.classList.toggle('apf-coord-return__hidden', hide);
        });
        if(coordReturnEmptyState){
          coordReturnEmptyState.hidden = total !== 0;
        }
        if(coordReturnPagerLabel){
          coordReturnPagerLabel.textContent = totalPages ? ((coordReturnPage + 1) + '/' + totalPages) : '0/0';
        }
        if(coordReturnPagerPrev){
          coordReturnPagerPrev.disabled = totalPages === 0 || coordReturnPage === 0;
        }
        if(coordReturnPagerNext){
          coordReturnPagerNext.disabled = totalPages === 0 || coordReturnPage >= (totalPages - 1);
        }
      }

      if(coordReturnMonthSelect){
        coordReturnMonthSelect.addEventListener('change', ()=>renderCoordReturnGrid(true));
      }
      if(coordReturnPagerPrev){
        coordReturnPagerPrev.addEventListener('click', ()=>{
          if(coordReturnPage > 0){
            coordReturnPage -= 1;
            renderCoordReturnGrid(false);
          }
        });
      }
      if(coordReturnPagerNext){
        coordReturnPagerNext.addEventListener('click', ()=>{
          const totalPages = coordReturnFiltered.length ? Math.ceil(coordReturnFiltered.length / COORD_RETURN_PAGE_SIZE) : 0;
          if(coordReturnPage < (totalPages - 1)){
            coordReturnPage += 1;
            renderCoordReturnGrid(false);
          }
        });
      }
      renderCoordReturnGrid(true);

      function buildCoordSection(title, rows){
        if(!rows || typeof rows !== 'object'){ return null; }
        const keys = Object.keys(rows);
        if(!keys.length){ return null; }
        const section = document.createElement('div');
        section.className = 'apf-coord-modal__section';
        const heading = document.createElement('h4');
        heading.textContent = title;
        section.appendChild(heading);
        const dl = document.createElement('dl');
        keys.forEach(label=>{
          const dt = document.createElement('dt');
          dt.textContent = label;
          const dd = document.createElement('dd');
          const value = rows[label];
          dd.textContent = (value === null || value === undefined || value === '') ? '—' : value;
          dl.appendChild(dt);
          dl.appendChild(dd);
        });
        section.appendChild(dl);
        return section;
      }

      function updateFaepaBox(group){
        if(!coordFaepaBox){ return; }
        if(!group){
          coordFaepaBox.hidden = true;
          return;
        }
        coordFaepaBox.hidden = false;
        const pending = group.counts && typeof group.counts.pending === 'number' ? group.counts.pending : 0;
        const forwarded = !!group.faepa_forwarded;
        if(coordFaepaBatch){
          coordFaepaBatch.value = group.id || '';
        }
        if(coordFaepaNote){
          coordFaepaNote.value = group.faepa_forwarded_note || '';
          coordFaepaNote.readOnly = forwarded;
        }
        if(coordFaepaSubmit){
          coordFaepaSubmit.disabled = forwarded || pending > 0;
          coordFaepaSubmit.style.display = forwarded ? 'none' : '';
        }
        if(coordFaepaHint){
          if(forwarded){
            coordFaepaHint.textContent = group.faepa_forwarded_note
              ? 'Observação registrada pelo financeiro.'
              : 'Lote já enviado para a FAEPA.';
          }else if(pending > 0){
            coordFaepaHint.textContent = 'Valide todos os colaboradores antes de enviar.';
          }else{
            coordFaepaHint.textContent = 'Confirme o recebimento e envie para a FAEPA.';
          }
        }
        if(coordFaepaStatus){
          const pendingLabel = pending > 0 ? (pending + ' pendente(s) para validar') : 'Aguardando envio à FAEPA';
          coordFaepaStatus.textContent = forwarded
            ? 'Enviado à FAEPA' + (group.faepa_forwarded_label ? ' em ' + group.faepa_forwarded_label : '')
            : pendingLabel;
          let statusClass = 'apf-coord-faepa__status';
          if(!forwarded){
            statusClass += pending > 0 ? ' apf-coord-faepa__status--error' : ' apf-coord-faepa__status--pending';
          }
          coordFaepaStatus.className = statusClass;
        }
        if(coordFaepaForm){
          coordFaepaForm.classList.toggle('is-disabled', forwarded);
        }
      }

      function renderCoordReturnGroup(group){
        if(!coordReturnBody){
          return;
        }
        if(!group){
          coordReturnBody.innerHTML = '<p class="apf-coord-return-modal__empty">Nenhum dado disponível.</p>';
          updateFaepaBox(null);
        return;
      }
      coordReturnBody.innerHTML = '';
      if(coordReturnTitle){
        coordReturnTitle.textContent = group.title || 'Retorno do coordenador';
        }
        if(coordReturnSubtitle){
          const parts = [];
          if(group.coordinator){
            if(group.coordinator.name){
              parts.push('Coordenador: ' + group.coordinator.name);
            }
            if(group.coordinator.course){
              parts.push('Curso: ' + group.coordinator.course);
            }
          }
          if(group.submitted_label){
            parts.push('Enviado em ' + group.submitted_label);
          }
          coordReturnSubtitle.textContent = parts.join(' • ');
        }
        if(coordReturnCounts){
          const approved = group.counts && typeof group.counts.approved === 'number' ? group.counts.approved : 0;
          const rejected = group.counts && typeof group.counts.rejected === 'number' ? group.counts.rejected : 0;
          if(coordReturnCountApproved){
            const num = coordReturnCountApproved.querySelector('strong');
            if(num){ num.textContent = approved; }
          }
          if(coordReturnCountRejected){
            const num = coordReturnCountRejected.querySelector('strong');
            if(num){ num.textContent = rejected; }
          }
        }
        updateFaepaBox(group);
        if(group.message){
          const message = document.createElement('p');
          message.className = 'apf-coord-modal__note';
          message.textContent = group.message;
          coordReturnBody.appendChild(message);
        }
        const paidCount = group.items ? group.items.filter(it=>!!it.faepa_paid).length : 0;
        const items = Array.isArray(group.items) ? group.items : [];
        if(!items.length){
          const empty = document.createElement('p');
          empty.className = 'apf-coord-return-modal__empty';
          empty.textContent = 'Nenhum colaborador encontrado neste retorno.';
          coordReturnBody.appendChild(empty);
          return;
        }
        const list = document.createElement('div');
        list.className = 'apf-coord-modal__list';
        items.forEach(item=>{
          const entry = document.createElement('article');
          entry.className = 'apf-coord-modal__entry';
          const toggle = document.createElement('button');
          toggle.type = 'button';
          toggle.className = 'apf-coord-modal__toggle';
          toggle.setAttribute('aria-expanded','false');
          const summary = document.createElement('div');
          summary.className = 'apf-coord-modal__summary';
          const name = document.createElement('strong');
          name.textContent = item.name || 'Colaborador';
          const value = document.createElement('span');
          value.textContent = item.value || '';
          summary.appendChild(name);
          summary.appendChild(value);
          toggle.appendChild(summary);
          const meta = document.createElement('div');
          meta.className = 'apf-coord-modal__meta';
          if(item.status){
            const pill = document.createElement('span');
            pill.className = 'apf-coord-modal__pill apf-coord-modal__pill--' + item.status;
            pill.textContent = item.status_label || item.status;
            meta.appendChild(pill);
          }
          if(item.decision_label){
            const dateLabel = document.createElement('span');
            const prefix = item.status === 'approved'
              ? 'Aprovado em '
              : (item.status === 'rejected' ? 'Recusado em ' : 'Atualizado em ');
            dateLabel.textContent = prefix + item.decision_label;
            meta.appendChild(dateLabel);
          }
          if(item.faepa_paid_label){
            const paidLabel = document.createElement('span');
            paidLabel.className = 'apf-coord-modal__pill apf-coord-modal__pill--paid';
            paidLabel.textContent = 'Pago em ' + item.faepa_paid_label;
            meta.appendChild(paidLabel);
          }
          toggle.appendChild(meta);
          const chevron = document.createElement('span');
          chevron.className = 'apf-coord-modal__chevron';
          toggle.appendChild(chevron);

          const detailsWrap = document.createElement('div');
          detailsWrap.className = 'apf-coord-modal__details';
          detailsWrap.hidden = true;
          if(item.note){
            if(item.status === 'rejected'){
              const noteTitle = document.createElement('h5');
              noteTitle.className = 'apf-coord-modal__note-title';
              noteTitle.textContent = 'Motivo da recusa:';
              detailsWrap.appendChild(noteTitle);
            }
            const note = document.createElement('p');
            note.className = 'apf-coord-modal__note';
            note.textContent = item.note;
            detailsWrap.appendChild(note);
          }
          if(item.faepa_payment_note || item.faepa_payment_attachment){
            const proofBox = document.createElement('div');
            proofBox.className = 'apf-coord-modal__proof';
            const proofTitle = document.createElement('h5');
            proofTitle.textContent = 'Comprovante do pagamento';
            proofBox.appendChild(proofTitle);
            if(item.faepa_payment_note){
              const note = document.createElement('p');
              note.textContent = item.faepa_payment_note;
              proofBox.appendChild(note);
              const coordNote = document.createElement('p');
              coordNote.textContent = 'Mensagem ao coordenador: ' + item.faepa_payment_note;
              proofBox.appendChild(coordNote);
              const collabNote = document.createElement('p');
              collabNote.textContent = 'Mensagem ao colaborador: ' + item.faepa_payment_note;
              proofBox.appendChild(collabNote);
            }
            if(item.faepa_payment_attachment){
              const link = document.createElement('a');
              link.href = item.faepa_payment_attachment;
              link.target = '_blank';
              link.rel = 'noopener';
              link.textContent = 'Ver anexo';
              proofBox.appendChild(link);
            }
            detailsWrap.appendChild(proofBox);
          }
          if(item.details){
            const paySection = buildCoordSection('Informações de pagamento', item.details.payment);
            const serviceSection = buildCoordSection('Prestação de serviço', item.details.service);
            const payoutSection = buildCoordSection('Dados para pagamento', item.details.payout);
            if(paySection){ detailsWrap.appendChild(paySection); }
            if(serviceSection){ detailsWrap.appendChild(serviceSection); }
            if(payoutSection){ detailsWrap.appendChild(payoutSection); }
            if(item.details.admin_url){
              const adminLink = document.createElement('a');
              adminLink.className = 'apf-coord-modal__admin';
              adminLink.href = item.details.admin_url;
              adminLink.target = '_blank';
              adminLink.rel = 'noopener';
              adminLink.textContent = 'Ver no painel';
              detailsWrap.appendChild(adminLink);
            }
          }
          toggle.addEventListener('click', ()=>{
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            detailsWrap.hidden = expanded;
          });
          entry.appendChild(toggle);
          entry.appendChild(detailsWrap);
          list.appendChild(entry);
        });
        coordReturnBody.appendChild(list);
      }

      function openCoordReturnModal(groupId){
        if(!coordReturnModal || !groupId){ return; }
        const data = coordReturnGroupsMap.get(groupId);
        if(!data){ return; }
        renderCoordReturnGroup(data);
        coordReturnModal.setAttribute('aria-hidden','false');
        coordReturnLastFocus = document.activeElement;
        (coordReturnDialog || coordReturnModal).focus();
        document.body.style.overflow = 'hidden';
      }

      function closeCoordReturnModal(){
        if(!coordReturnModal){ return; }
        coordReturnModal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        if(coordReturnLastFocus){ coordReturnLastFocus.focus(); }
        updateFaepaBox(null);
      }

      function renderArchiveGroups(ids){
        if(!coordArchiveBody){
          return;
        }
        if(!ids || !ids.length){
          coordArchiveBody.innerHTML = '<p class="apf-coord-archive__empty">Nenhum retorno arquivado disponível.</p>';
          return;
        }
        const groups = [];
        ids.forEach(id=>{
          if(coordReturnGroupsMap.has(id)){
            groups.push(coordReturnGroupsMap.get(id));
          }
        });
        if(!groups.length){
          coordArchiveBody.innerHTML = '<p class="apf-coord-archive__empty">Nenhum retorno arquivado disponível.</p>';
          return;
        }
        coordArchiveBody.innerHTML = '';
        groups.forEach(group=>{
          const entry = document.createElement('article');
          entry.className = 'apf-archive-entry';
          const toggle = document.createElement('button');
          toggle.type = 'button';
          toggle.className = 'apf-archive-entry__toggle';
          toggle.setAttribute('aria-expanded','false');
          const heading = document.createElement('h4');
          heading.textContent = group.title || 'Retorno do coordenador';
          const date = document.createElement('small');
          date.textContent = group.submitted_label ? ('Enviado em ' + group.submitted_label) : 'Data indisponível';
          const status = document.createElement('small');
          status.className = 'apf-archive-entry__status';
          status.textContent = group.faepa_forwarded
            ? 'Enviado à FAEPA' + (group.faepa_forwarded_label ? ' em ' + group.faepa_forwarded_label : '')
            : 'Aguardando envio à FAEPA';
          const chevron = document.createElement('span');
          chevron.className = 'apf-archive-entry__chevron';
          toggle.appendChild(heading);
          toggle.appendChild(date);
          toggle.appendChild(status);
          toggle.appendChild(chevron);
          const content = document.createElement('div');
          content.className = 'apf-archive-entry__content';
          content.hidden = true;
          if(group.message){
            const message = document.createElement('p');
            message.className = 'apf-archive-entry__message';
            message.textContent = group.message;
            content.appendChild(message);
          }
          const collabsContainer = document.createElement('div');
          collabsContainer.className = 'apf-archive-collabs';
          const collabs = Array.isArray(group.items) ? group.items : [];
          collabs.forEach(item=>{
            const collab = document.createElement('div');
            collab.className = 'apf-archive-collab';
            const collabToggle = document.createElement('button');
            collabToggle.type = 'button';
            collabToggle.className = 'apf-archive-collab__toggle';
            collabToggle.setAttribute('aria-expanded','false');
            const summary = document.createElement('div');
            summary.className = 'apf-archive-collab__summary';
            const name = document.createElement('strong');
            name.textContent = item.name || 'Colaborador';
            const value = document.createElement('span');
            value.textContent = item.value || '';
            summary.appendChild(name);
            summary.appendChild(value);
            collabToggle.appendChild(summary);
            const meta = document.createElement('div');
            meta.className = 'apf-archive-collab__meta';
            if(item.status){
              const pill = document.createElement('span');
              pill.className = 'apf-archive-collab__pill apf-archive-collab__pill--' + item.status;
              pill.textContent = item.status_label || item.status;
              meta.appendChild(pill);
            }
            if(item.decision_label){
              const decision = document.createElement('span');
              const prefix = item.status === 'approved'
                ? 'Aprovado em '
                : (item.status === 'rejected' ? 'Recusado em ' : 'Atualizado em ');
              decision.textContent = prefix + item.decision_label;
              meta.appendChild(decision);
            }
            collabToggle.appendChild(meta);
            const collabChevron = document.createElement('span');
            collabChevron.className = 'apf-archive-collab__chevron';
            collabToggle.appendChild(collabChevron);
            const detailWrap = document.createElement('div');
            detailWrap.className = 'apf-archive-collab__details';
            detailWrap.hidden = true;
            if(item.note){
              const note = document.createElement('p');
              note.className = 'apf-coord-modal__note';
              note.textContent = item.note;
              detailWrap.appendChild(note);
            }
            if(item.details){
            const paySection = buildCoordSection('Informações de pagamento', item.details.payment);
            const serviceSection = buildCoordSection('Prestação de serviço', item.details.service);
            const payoutSection = buildCoordSection('Dados para pagamento', item.details.payout);
              if(paySection){ detailWrap.appendChild(paySection); }
              if(serviceSection){ detailWrap.appendChild(serviceSection); }
              if(payoutSection){ detailWrap.appendChild(payoutSection); }
              if(item.details.admin_url){
                const adminLink = document.createElement('a');
                adminLink.className = 'apf-coord-modal__admin';
                adminLink.href = item.details.admin_url;
                adminLink.target = '_blank';
                adminLink.rel = 'noopener';
                adminLink.textContent = 'Ver no painel';
                detailWrap.appendChild(adminLink);
              }
            }
            collabToggle.addEventListener('click', ()=>{
              const expanded = collabToggle.getAttribute('aria-expanded') === 'true';
              collabToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
              detailWrap.hidden = expanded;
            });
            collab.appendChild(collabToggle);
            collab.appendChild(detailWrap);
            collabsContainer.appendChild(collab);
          });
          content.appendChild(collabsContainer);
          toggle.addEventListener('click', ()=>{
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            content.hidden = expanded;
          });
          entry.appendChild(toggle);
          entry.appendChild(content);
          coordArchiveBody.appendChild(entry);
        });
      }

      function openCoordArchiveModal(ids){
        if(!coordArchiveModal){ return; }
        renderArchiveGroups(ids);
        coordArchiveModal.setAttribute('aria-hidden','false');
        coordArchiveLastFocus = document.activeElement;
        (coordArchiveDialog || coordArchiveModal).focus();
        document.body.style.overflow = 'hidden';
      }

      function closeCoordArchiveModal(){
        if(!coordArchiveModal){ return; }
        coordArchiveModal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        if(coordArchiveLastFocus){ coordArchiveLastFocus.focus(); }
      }

      coordReturnCloseTriggers.forEach(trigger=>{
        trigger.addEventListener('click', closeCoordReturnModal);
      });
      coordArchiveCloseTriggers.forEach(trigger=>{
        trigger.addEventListener('click', closeCoordArchiveModal);
      });

      document.addEventListener('click', (event)=>{
        const trigger = event.target.closest('.apf-coord-return__details');
        if(trigger){
          const groupId = trigger.getAttribute('data-coord-group');
          if(groupId){
            openCoordReturnModal(groupId);
          }
        }
        const archiveBtn = event.target.closest('[data-coord-archive]');
        if(archiveBtn && coordArchiveModal){
          const idsRaw = archiveBtn.getAttribute('data-coord-archive') || '';
          const ids = idsRaw.split(',').map(v=>v.trim()).filter(Boolean);
          if(ids.length){
            openCoordArchiveModal(ids);
          }
        }
      });
      document.addEventListener('keydown', (event)=>{
        if(event.key === 'Escape'){
          if(coordReturnModal && coordReturnModal.getAttribute('aria-hidden') === 'false'){
            closeCoordReturnModal();
          }
          if(coordArchiveModal && coordArchiveModal.getAttribute('aria-hidden') === 'false'){
            closeCoordArchiveModal();
          }
        }
      });

      if(pagerPrev){
        pagerPrev.addEventListener('click', ()=>{
          if(currentPage > 0){
            currentPage -= 1;
            applyPagination();
          }
        });
      }
      if(pagerNext){
        pagerNext.addEventListener('click', ()=>{
          const totalPages = Math.max(1, Math.ceil(visibleRows.length / PAGE_SIZE));
          if(currentPage < (totalPages - 1)){
            currentPage += 1;
            applyPagination();
          }
        });
      }

      // Busca
      btnSearch.addEventListener('click', runSearch);
      btnClear.addEventListener('click', clearFilter);
      input.addEventListener('keydown', function(e){
        if(e.key === 'Enter'){
          e.preventDefault();
          runSearch();
        }
      });
      if(directorSelect){
        directorSelect.addEventListener('change', function(){
          setActiveCoordinatorKey(this.value || '');
          runSearch();
        });
        directorSelect.addEventListener('keydown', function(e){
          if(e.key === 'Enter'){
            e.preventDefault();
            runSearch();
          }
        });
      }

      // contador inicial
      applyFilter();
    })();
    </script>
    <?php
    return ob_get_clean();
});
