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

        return is_string( $name ) ? trim( wp_strip_all_tags( $name ) ) : '';
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
                    if ( $label !== '' ) {
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
    function apf_scheduler_make_recipient_key( $user_id, $email ) {
        $user_id = (int) $user_id;
        $email   = sanitize_email( $email );
        if ( $user_id > 0 ) {
            return 'user_' . $user_id;
        }
        if ( $email !== '' ) {
            return 'email_' . strtolower( $email );
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

                    if ( '' === $recipient_email ) {
                        continue;
                    }
                    if ( '' === $recipient_key ) {
                        $recipient_key = apf_scheduler_make_recipient_key( $recipient_user, $recipient_email );
                    }
                    if ( '' === $recipient_name ) {
                        $recipient_name = $recipient_email;
                    }

                    $recipients[] = array(
                        'key'     => $recipient_key,
                        'user_id' => $recipient_user,
                        'name'    => $recipient_name,
                        'email'   => $recipient_email,
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

/* ====== DASHBOARD FINANCEIRO: shortcode [apf_inbox] ====== */
add_shortcode('apf_inbox', function () {

    if ( ! is_user_logged_in() || ! current_user_can('edit_posts') ) {
        return '<p>Faça login com um usuário autorizado para ver as submissões.</p>';
    }

    $directors = get_option('apf_directors', array());
    if ( ! is_array($directors) ) {
        $directors = array();
    }

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
                $current_signature = 'add|' . strtolower($course) . '|' . strtolower($director);

                if ( $course && $director ) {
                    if ( $transient_key && $current_signature === get_transient($transient_key) ) {
                        $redirect_notice = 'Nada foi alterado (a última ação já tinha sido aplicada).';
                    } else {
                        $directors[] = array(
                            'id'       => uniqid('dir_'),
                            'course'   => $course,
                            'director' => $director,
                        );
                        update_option('apf_directors', $directors, false);
                        $redirect_notice = 'Diretor adicionado.';
                        if ( $transient_key && $current_signature ) {
                            set_transient($transient_key, $current_signature, 2 * MINUTE_IN_SECONDS);
                        }
                    }
                } else {
                    $redirect_notice = 'Informe curso e diretor para adicionar.';
                    $redirect_type   = 'error';
                }
            } elseif ( $action === 'update' ) {
                $id       = isset($_POST['apf_dir_id']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', wp_unslash( $_POST['apf_dir_id'] )) : '';
                $course   = sanitize_text_field( wp_unslash( $_POST['apf_dir_course'] ?? '' ) );
                $director = sanitize_text_field( wp_unslash( $_POST['apf_dir_name'] ?? '' ) );
                $current_signature = 'upd|' . strtolower($id) . '|' . strtolower($course) . '|' . strtolower($director);
                $updated  = false;
                $already_applied = false;

                foreach ( $directors as $idx => $item ) {
                    $item_id = isset($item['id']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', (string) $item['id']) : '';
                    if ( $item_id === $id ) {
                        if ( $course && $director ) {
                            if ( $transient_key && $current_signature === get_transient($transient_key) ) {
                                $redirect_notice = 'Nada foi alterado (a última ação já tinha sido aplicada).';
                                $updated = true;
                                $already_applied = true;
                            } else {
                                $directors[$idx]['course']   = $course;
                                $directors[$idx]['director'] = $director;
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
                        $redirect_notice = 'Diretor atualizado.';
                    } elseif ( empty($redirect_notice) ) {
                        $redirect_notice = 'Diretor atualizado.';
                    }
                } else {
                    $redirect_notice = 'Não foi possível atualizar o diretor selecionado.';
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
                        $redirect_notice = 'Diretor removido.';
                        if ( $transient_key && $current_signature ) {
                            set_transient($transient_key, $current_signature, 2 * MINUTE_IN_SECONDS);
                        }
                    } else {
                        $redirect_notice = 'Diretor não encontrado para remoção.';
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
                ? 'Não foi possível processar a solicitação.'
                : 'Diretores atualizados.';
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

    // ordena lista por curso/diretor para exibição consistente
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

    $director_filter_choices = array();
    if ( ! empty( $directors ) ) {
        foreach ( $directors as $entry ) {
            $director_name = isset( $entry['director'] ) ? trim( (string) $entry['director'] ) : '';
            if ( '' === $director_name ) {
                continue;
            }
            $course_name = isset( $entry['course'] ) ? trim( (string) $entry['course'] ) : '';
            $key   = apf_inbox_build_director_key( $director_name, $course_name );
            $label = ( '' !== $course_name ) ? $director_name . ' — ' . $course_name : $director_name;
            $director_filter_choices[ $key ] = array(
                'label'    => $label,
                'director' => $director_name,
                'course'   => $course_name,
            );
        }
    }
    if ( ! empty( $director_filter_choices ) ) {
        uasort( $director_filter_choices, function( $a, $b ){
            return strcasecmp( $a['label'], $b['label'] );
        });
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

            $tipo = $m($id,'pessoa_tipo'); // pf/pj
            $nome = ($tipo==='pj') ? $m($id,'nome_empresa') : $m($id,'nome_prof');
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

            $banco  = trim( ($m($id,'banco') ?: '') .' / '. ($m($id,'agencia') ?: '') .' / '. ($m($id,'conta') ?: '') );
            $admin_url = admin_url('post.php?post='.$id.'&action=edit');
            $director_key = ( $dir || $curso ) ? apf_inbox_build_director_key( $dir, $curso ) : '';
            $director_label = trim( $dir ?: '' );
            if ( $director_label !== '' && $curso ) {
                $director_label .= ' — ' . $curso;
            } elseif ( '' === $director_label ) {
                $director_label = $curso ?: '';
            }
            $payload = array(
              'Data'                   => get_the_date('Y-m-d H:i'),
              'Tipo'                   => strtoupper($tipo ?: '—'),
              'Nome/Empresa'           => $nome ?: '—',
              'Telefone'               => $tel ?: '—',
              'E-mail'                 => $mail ?: '—',
              'Valor (R$)'             => $valor_fmt ?: '—',
              'Diretor'                => $dir ?: '—',
              'Doc. Fiscal'            => $docf ?: '—',
              'Data do Serviço'        => $dserv ?: '—',
              'Classificação'          => $class ?: '—',
              'Curso'                  => $curso ?: '—',
              'Carga Horária (CH)'     => $ch ?: '—',
              'Banco/Agência/Conta'    => $banco ?: '—',
              '_admin_url'             => $admin_url,
            );
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
                'author_id'      => $author_id,
                'email'          => $mail,
                'name'           => $nome ?: ( $mail ?: '' ),
            );
            $group_map[ $group_key ]['search_parts'][] = $concat;
        endwhile;
        wp_reset_postdata();
    } else {
        wp_reset_postdata();
    }

    $group_rows = array();
    foreach ( $group_map as $group ) {
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
            );
        }
        $group_rows[] = array(
            'latest'        => $latest,
            'history'       => $history,
            'search'        => implode( ' ', $group['search_parts'] ),
            'count'         => $total,
            'director_key'  => $latest['director_key'],
            'director_label'=> $latest['director_label'],
            'author_id'     => isset( $latest['author_id'] ) ? (int) $latest['author_id'] : 0,
            'email'         => isset( $latest['email'] ) ? sanitize_email( $latest['email'] ) : '',
            'name'          => isset( $latest['name'] ) ? sanitize_text_field( $latest['name'] ) : '',
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

    $scheduler_provider_index = array();
    foreach ( $group_rows as $bundle ) {
        $latest    = $bundle['latest'];
        $author_id = isset( $bundle['author_id'] ) ? (int) $bundle['author_id'] : 0;
        $email     = isset( $bundle['email'] ) ? sanitize_email( $bundle['email'] ) : '';
        $name      = isset( $bundle['name'] ) ? sanitize_text_field( $bundle['name'] ) : '';

        if ( '' === $email && $author_id > 0 ) {
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
            if ( ! empty( $payload['Nome/Empresa'] ) && $payload['Nome/Empresa'] !== '—' ) {
                $name = sanitize_text_field( $payload['Nome/Empresa'] );
            } elseif ( ! empty( $payload['E-mail'] ) && $payload['E-mail'] !== '—' ) {
                $name = sanitize_text_field( $payload['E-mail'] );
            }
        }
        if ( '' === $name ) {
            $name = $email ?: '';
        }

        $key = apf_scheduler_make_recipient_key( $author_id, $email );
        if ( '' === $key || isset( $scheduler_provider_index[ $key ] ) ) {
            continue;
        }

        $name  = sanitize_text_field( $name );
        $label = $name;
        if ( $email ) {
            $label .= ' — ' . $email;
        }
        $label = sanitize_text_field( $label );

        $scheduler_provider_index[ $key ] = array(
            'key'     => $key,
            'user_id' => $author_id,
            'name'    => $name,
            'email'   => $email,
            'label'   => $label,
        );
    }
    $scheduler_providers = array_values( $scheduler_provider_index );
    usort( $scheduler_providers, function( $a, $b ){
        return strcasecmp( $a['label'], $b['label'] );
    } );

    $scheduler_events = apf_scheduler_get_events();

    if ( isset( $_POST['apf_scheduler_action'] ) ) {
        $redirect_notice = '';
        $redirect_type   = 'success';

        if ( ! isset( $_POST['apf_scheduler_nonce'] ) || ! wp_verify_nonce( $_POST['apf_scheduler_nonce'], 'apf_scheduler_manage' ) ) {
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
                $recipient_keys = array();
                if ( is_array( $raw_recipients ) ) {
                    foreach ( $raw_recipients as $key ) {
                        $key = preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', (string) $key );
                        if ( $key !== '' ) {
                            $recipient_keys[] = $key;
                        }
                    }
                } elseif ( is_string( $raw_recipients ) && $raw_recipients !== '' ) {
                    $chunks = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
                    foreach ( $chunks as $chunk ) {
                        $chunk = preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', $chunk );
                        if ( $chunk !== '' ) {
                            $recipient_keys[] = $chunk;
                        }
                    }
                }
                $recipient_keys = array_values( array_unique( $recipient_keys ) );

                $recipient_payload = array();
                foreach ( $recipient_keys as $key ) {
                    if ( isset( $scheduler_provider_index[ $key ] ) ) {
                        $provider = $scheduler_provider_index[ $key ];
                        $recipient_payload[] = array(
                            'key'     => $provider['key'],
                            'user_id' => $provider['user_id'],
                            'name'    => $provider['name'],
                            'email'   => $provider['email'],
                        );
                    }
                }

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

    $scheduler_events_json = array();
    $scheduler_events_human = array();

    foreach ( $scheduler_events as $event ) {
        $scheduler_events_json[] = array(
            'id'    => $event['id'],
            'date'  => $event['date'],
            'title' => $event['title'],
        );

        $names = array();
        foreach ( $event['recipients'] as $recipient ) {
            $display = $recipient['name'];
            if ( $recipient['email'] ) {
                $display .= ' <' . $recipient['email'] . '>';
            }
            $names[] = $display;
        }
        $scheduler_events_human[] = array(
            'id'         => $event['id'],
            'date'       => $event['date'],
            'title'      => $event['title'],
            'recipients' => $names,
        );
    }

    $scheduler_events_attr = esc_attr( wp_json_encode( $scheduler_events_json, JSON_UNESCAPED_UNICODE ) );
    $scheduler_providers_attr = esc_attr( wp_json_encode( $scheduler_providers, JSON_UNESCAPED_UNICODE ) );

    ob_start(); ?>
    <div class="apf-inbox-wrap" aria-live="polite">
      <!-- Gestão de Diretores -->
      <section class="apf-directors" aria-labelledby="apfDirectorsTitle">
        <div class="apf-directors__head">
          <div>
            <h2 id="apfDirectorsTitle">Diretores por curso</h2>
            <p>Cadastre os coordenadores fixos que ficarão disponíveis no formulário público.</p>
          </div>
        </div>

        <?php if ( $director_notice ) : ?>
          <div class="apf-directors__notice apf-directors__notice--<?php echo esc_attr($director_notice_type); ?>">
            <?php echo esc_html($director_notice); ?>
          </div>
        <?php endif; ?>

        <form method="post" class="apf-directors__form">
          <?php wp_nonce_field('apf_directors_manage','apf_directors_nonce'); ?>
          <input type="hidden" name="apf_directors_action" value="add">
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
            <label>Diretor
              <input type="text" name="apf_dir_name" required placeholder="Nome completo do diretor">
            </label>
          </div>
          <div class="apf-directors__actions">
            <button type="submit" class="apf-btn apf-btn--primary">Adicionar diretor</button>
          </div>
        </form>

        <?php if ( ! empty($directors) ) : ?>
          <div class="apf-directors__list">
            <?php foreach ( $directors as $entry ) : ?>
              <form method="post" class="apf-directors__item">
                <?php wp_nonce_field('apf_directors_manage','apf_directors_nonce'); ?>
                <input type="hidden" name="apf_dir_id" value="<?php echo esc_attr($entry['id'] ?? ''); ?>">
                <div class="apf-directors__grid">
                  <label>Curso
                    <?php if ( $course_select_available ) :
                        $current_course = isset( $entry['course'] ) ? (string) $entry['course'] : '';
                    ?>
                      <select name="apf_dir_course" required>
                        <option value="" <?php selected( $current_course, '' ); ?>><?php echo esc_html('Selecione um curso'); ?></option>
                        <?php foreach ( $course_choices as $course_label ) : ?>
                          <option value="<?php echo esc_attr( $course_label ); ?>" title="<?php echo esc_attr( $course_label ); ?>" <?php selected( $current_course, $course_label ); ?>><?php echo esc_html( $course_label ); ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else : ?>
                      <input type="text" name="apf_dir_course" value="<?php echo esc_attr($entry['course'] ?? ''); ?>" required>
                    <?php endif; ?>
                  </label>
                  <label>Diretor
                    <input type="text" name="apf_dir_name" value="<?php echo esc_attr($entry['director'] ?? ''); ?>" required>
                  </label>
                </div>
                <div class="apf-directors__item-actions">
                  <button type="submit" name="apf_directors_action" value="update" class="apf-btn apf-btn--primary">Salvar</button>
                  <button type="submit" name="apf_directors_action" value="delete" class="apf-btn apf-btn--danger" onclick="return confirm('Remover este diretor?');">Excluir</button>
                </div>
              </form>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- Agenda Financeira -->
      <section class="apf-scheduler" aria-labelledby="apfSchedulerTitle">
        <div class="apf-scheduler__head">
          <div>
            <h2 id="apfSchedulerTitle">Agenda financeira</h2>
            <p>Selecione um dia, escolha os prestadores e informe o título do aviso que ficará disponível no portal.</p>
          </div>
        </div>

        <?php if ( $scheduler_notice ) : ?>
          <div class="apf-notice apf-notice--<?php echo esc_attr( $scheduler_notice_type ); ?>">
            <?php echo esc_html( $scheduler_notice ); ?>
          </div>
        <?php endif; ?>

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
            <label class="apf-scheduler__field">
              <span>Título do aviso</span>
              <input type="text" name="apf_scheduler_title" id="apfSchedulerTitleInput" maxlength="120" placeholder="Ex.: Atualizar dados cadastrais" required>
            </label>
            <label class="apf-scheduler__field">
              <span>Buscar prestadores</span>
              <input type="search" id="apfSchedulerSearch" placeholder="Digite nome ou e-mail">
            </label>
            <div class="apf-scheduler__providers">
              <p class="apf-scheduler__providers-empty">Nenhum prestador encontrado.</p>
            </div>
            <div class="apf-scheduler__actions">
              <button type="submit" class="apf-btn apf-btn--primary">Agendar aviso</button>
            </div>
          </form>
        </div>

        <?php if ( ! empty( $scheduler_events_human ) ) : ?>
          <div class="apf-scheduler__list">
            <h3>Próximos avisos</h3>
            <ul>
              <?php foreach ( $scheduler_events_human as $event_entry ) : ?>
                <li>
                  <div class="apf-scheduler__list-meta">
                    <span class="apf-scheduler__list-date"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $event_entry['date'] ) ) ); ?></span>
                    <span class="apf-scheduler__list-title"><?php echo esc_html( $event_entry['title'] ); ?></span>
                  </div>
                  <?php if ( ! empty( $event_entry['recipients'] ) ) : ?>
                    <p class="apf-scheduler__list-recipients">
                      <?php echo esc_html( 'Destinatários: ' . implode( '; ', $event_entry['recipients'] ) ); ?>
                    </p>
                  <?php endif; ?>
                  <form method="post" class="apf-scheduler__list-remove">
                    <?php wp_nonce_field( 'apf_scheduler_manage', 'apf_scheduler_nonce' ); ?>
                    <input type="hidden" name="apf_scheduler_action" value="delete">
                    <input type="hidden" name="apf_scheduler_event" value="<?php echo esc_attr( $event_entry['id'] ); ?>">
                    <button type="submit" class="apf-btn apf-btn--ghost" onclick="return confirm('Remover este aviso do calendário?');">Remover</button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </section>

      <!-- Toolbar / Busca -->
      <div class="apf-toolbar">
        <form class="apf-search" role="search" aria-label="Busca no dashboard" onsubmit="return false;">
          <div class="apf-search-field">
            <input id="apfQuery" type="search" inputmode="search" autocomplete="off"
                   placeholder="Pesquisar por nome, CPF, CNPJ, telefone, e-mail, nº controle, doc fiscal..." aria-label="Pesquisar" />
          </div>
          <?php if ( ! empty( $director_filter_choices ) ) : ?>
            <label class="apf-filter" for="apfDirectorFilter">
              <span>Diretor/Curso</span>
              <select id="apfDirectorFilter">
                <option value=""><?php echo esc_html( 'Todos os diretores' ); ?></option>
                <?php foreach ( $director_filter_choices as $option_key => $option_data ) : ?>
                  <option value="<?php echo esc_attr( $option_key ); ?>">
                    <?php echo esc_html( $option_data['label'] ); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php endif; ?>
          <div class="apf-search-actions">
            <button id="apfBtnSearch" type="button" class="apf-btn apf-btn--primary">Buscar</button>
            <button id="apfBtnClear"  type="button" class="apf-btn apf-btn--ghost">Limpar</button>
          </div>
        </form>
        <div class="apf-count"><span id="apfCount" class="apf-badge"></span></div>
      </div>

      <div class="apf-table-scroller">
        <table id="apfTable" class="apf-table" aria-describedby="apfCount">
          <thead>
            <tr>
              <th scope="col">Tipo</th>
              <th scope="col">Nome/Empresa</th>
              <th scope="col">Telefone</th>
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
                $search_blob  = $bundle['search'];
                $nome_display = $payload['Nome/Empresa'];
          ?>
            <tr data-search="<?php echo esc_attr( $search_blob ); ?>" data-json="<?php echo $data_json; ?>" data-history="<?php echo $history_json; ?>" data-director-key="<?php echo esc_attr( $director_key ); ?>" data-director-label="<?php echo esc_attr( $director_label ); ?>" data-total="<?php echo esc_attr( $count_badge ); ?>">
              <td class="apf-uppercase"><?php echo esc_html( $payload['Tipo'] ); ?></td>
              <td class="apf-break"<?php if ( $count_badge > 1 ) echo ' data-envios="'.esc_attr( $count_badge . ' envios' ).'"'; ?>>
                <?php echo esc_html( $nome_display ); ?>
              </td>
              <td class="apf-break"><?php echo esc_html( $payload['Telefone'] ); ?></td>
              <td class="apf-break"><?php echo esc_html( $payload['E-mail'] ); ?></td>
              <td class="apf-col--num apf-nowrap" title="<?php echo esc_attr( $payload['Valor (R$)'] ); ?>"><?php echo esc_html( $payload['Valor (R$)'] ); ?></td>
              <td class="apf-break"><?php echo esc_html( $payload['Curso'] ); ?></td>
              <td class="apf-actions">
                <button type="button" class="apf-link apf-btn--inline apf-btn-details">Detalhes</button>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7">Nenhuma submissão encontrada.</td></tr>
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
    </div>

    <style>
      /* ===== Tokens / dark mode */
      :root{
        --apf-bg:#ffffff; --apf-text:#111827; --apf-muted:#6b7280;
        --apf-border:#e5e7eb; --apf-soft:#f8fafc; --apf-primary:#1f6feb; --apf-primary-ink:#ffffff;
        --apf-focus:0 0 0 3px rgba(31,111,235,.2); --apf-shadow:0 1px 2px rgba(16,24,40,.06),0 1px 3px rgba(16,24,40,.1);
        --apf-radius:12px; --apf-radius-sm:10px; --apf-row:#fcfdff; --apf-row-hover:#f3f7ff; --apf-highlight:#fff1a8;
        --apf-modal-overlay: rgba(2,6,23,.55);
      }
      @media (prefers-color-scheme: dark){
        :root{
          --apf-bg:#0b1220; --apf-text:#e5e7eb; --apf-muted:#9ca3af; --apf-border:#1f2937; --apf-soft:#0f172a;
          --apf-row:#0c1628; --apf-row-hover:#12203a; --apf-primary:#3b82f6; --apf-primary-ink:#0b1220;
          --apf-highlight:#3a2f00; --apf-shadow:none; --apf-modal-overlay: rgba(0,0,0,.65);
        }
      }

      .apf-inbox-wrap{ font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial; color:var(--apf-text); }

      /* ===== Busca */
      .apf-toolbar{ display:flex; flex-wrap:wrap; align-items:flex-end; gap:16px; margin:8px 0 14px; }
      .apf-search{ display:flex; flex:1 1 520px; gap:12px; align-items:flex-end; flex-wrap:wrap; }
      .apf-search-field{ flex:2 1 320px; }
      .apf-filter{ display:flex; flex-direction:column; gap:4px; flex:1 1 220px; min-width:220px; }
      .apf-filter span{ font-size:12px; color:var(--apf-muted); font-weight:500; }
      .apf-filter select{
        height:42px; border:1px solid var(--apf-border); border-radius:10px;
        background:var(--apf-bg); color:var(--apf-text);
        padding:0 14px; font-size:14px; min-width:220px;
      }
      .apf-filter select:focus{ border-color:var(--apf-primary); box-shadow:var(--apf-focus); outline:none; }
      .apf-search input{
        width:100%; height:44px; border:1px solid var(--apf-border); border-radius:999px;
        padding:0 16px; font-size:15px; background:var(--apf-bg); color:var(--apf-text);
        outline:none; box-shadow:none;
      }
      .apf-search input::placeholder{ color:var(--apf-muted); }
      .apf-search input:focus{ box-shadow:var(--apf-focus); border-color:var(--apf-primary); }
      .apf-search-actions{ display:flex; gap:8px; }
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
      .apf-scheduler__actions{
        display:flex;
        justify-content:flex-end;
      }
      .apf-scheduler__list{
        border-top:1px solid var(--apf-border);
        padding-top:16px;
        display:flex;
        flex-direction:column;
        gap:12px;
      }
      .apf-scheduler__list h3{
        margin:0;
        font-size:15px;
      }
      .apf-scheduler__list ul{
        list-style:none;
        margin:0;
        padding:0;
        display:flex;
        flex-direction:column;
        gap:10px;
      }
      .apf-scheduler__list li{
        border:1px solid var(--apf-border);
        border-radius:10px;
        padding:12px;
        background:var(--apf-bg);
        display:flex;
        flex-direction:column;
        gap:10px;
      }
      .apf-scheduler__list-meta{
        display:flex;
        flex-wrap:wrap;
        gap:12px;
        align-items:center;
      }
      .apf-scheduler__list-date{
        font-weight:600;
        font-size:14px;
      }
      .apf-scheduler__list-title{
        font-size:14px;
      }
      .apf-scheduler__list-recipients{
        margin:0;
        font-size:13px;
        color:var(--apf-muted);
      }
      .apf-scheduler__list-remove{
        display:flex;
        justify-content:flex-end;
      }
      @media(max-width:1080px){
        .apf-scheduler__grid{
          grid-template-columns:1fr;
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
      .apf-count{ font-size:13px; color:var(--apf-muted); }
      .apf-badge{ display:inline-block; padding:4px 10px; border-radius:999px; background:var(--apf-soft); border:1px solid var(--apf-border); }
      .apf-break[data-envios]{ position:relative; padding-right:78px; }
      .apf-break[data-envios]::after{
        content: attr(data-envios);
        position:absolute; right:0; top:50%; transform:translateY(-50%);
        display:inline-flex; align-items:center; justify-content:center;
        padding:2px 8px;
        border-radius:999px;
        background:var(--apf-soft);
        border:1px solid var(--apf-border);
        font-size:11px;
        font-weight:600;
        color:var(--apf-muted);
        text-transform:uppercase;
        letter-spacing:.03em;
        white-space:nowrap;
      }

      /* ===== Tabela (enxuta) */
      .apf-table-scroller{ overflow:auto; border:1px solid var(--apf-border); border-radius:var(--apf-radius); background:var(--apf-bg); box-shadow:var(--apf-shadow); }
      .apf-table{ width:100%; border-collapse:separate; border-spacing:0; min-width:760px; }
      th, td{ word-break: normal; hyphens: manual; line-height:1.35; }
      .apf-table thead th{
        position:sticky; top:0; z-index:1; background:var(--apf-soft);
        text-align:left; padding:12px 14px; border-bottom:1px solid var(--apf-border); font-weight:600; color:#475467;
      }
      @media (prefers-color-scheme: dark){ .apf-table thead th{ color:#cbd5e1; } }
      .apf-table tbody td{ padding:12px 14px; border-bottom:1px solid var(--apf-border); vertical-align:top; }
      .apf-table tbody tr:nth-child(odd){ background:var(--apf-row); }
      .apf-table tbody tr:hover{ background:var(--apf-row-hover); }

      .apf-col--num{ text-align:right; font-variant-numeric:tabular-nums; }
      .apf-nowrap{ white-space:nowrap; text-overflow:ellipsis; overflow:hidden; max-width:180px; }
      .apf-break{ overflow-wrap:anywhere; }
      .apf-actions{ display:flex; gap:10px; align-items:center; }
      .apf-link{ color:var(--apf-primary); text-decoration:none; font-weight:500; }
      .apf-link:hover{ text-decoration:underline; }
      .apf-btn--inline{ background:transparent; border:none; padding:0; height:auto; }

      /* Highlight */
      .apf-hide{ display:none !important; }
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
      .apf-details dt{ color:var(--apf-muted); }
      .apf-details dd{ margin:0; }
      /* Mobile cards (agora com 7 colunas) */
      @media (max-width: 920px){
        .apf-toolbar{ flex-direction:column; align-items:stretch; }
        .apf-search{ width:100%; flex-direction:column; align-items:stretch; }
        .apf-search-field{ width:100%; }
        .apf-search-actions{ justify-content:flex-end; }
        .apf-filter{ width:100%; }
        .apf-filter select{ width:100%; }
        .apf-table{ min-width:100%; }
        .apf-table thead{ display:none; }
        .apf-table tbody tr{ display:grid; grid-template-columns:1fr 1fr; gap:6px 12px; padding:12px; border-bottom:1px solid var(--apf-border); }
        .apf-table tbody td{ display:block; padding:0; border:0; }
        .apf-break[data-envios]{ padding-right:0; }
        .apf-break[data-envios]::after{
          position:static;
          transform:none;
          margin-top:4px;
          display:inline-flex;
        }
        .apf-table tbody td::before{ content: attr(data-label); display:block; font-size:12px; color:var(--apf-muted); margin-bottom:2px; font-weight:500; }

        #apfTable tbody tr td:nth-child(1)::before{ content:"Tipo"; }
        #apfTable tbody tr td:nth-child(2)::before{ content:"Nome/Empresa"; }
        #apfTable tbody tr td:nth-child(3)::before{ content:"Telefone"; }
        #apfTable tbody tr td:nth-child(4)::before{ content:"E-mail"; }
        #apfTable tbody tr td:nth-child(5)::before{ content:"Valor (R$)"; }
        #apfTable tbody tr td:nth-child(6)::before{ content:"Curso"; }
        #apfTable tbody tr td:nth-child(7)::before{ content:"Ações"; }

        .apf-nowrap{ max-width:none; white-space:normal; }
      }
    </style>

    <script>
    (function(){
      const $ = (s,c)=> (c||document).querySelector(s);
      const $$ = (s,c)=> Array.from((c||document).querySelectorAll(s));

      function norm(s){ return (s||'').toString().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim(); }
      function digits(s){ return (s||'').toString().replace(/\D+/g,''); }
      const input = $('#apfQuery');
      const btnSearch = $('#apfBtnSearch');
      const btnClear  = $('#apfBtnClear');
      const directorSelect = $('#apfDirectorFilter');
      const rows = $$('#apfTable tbody tr');
      const countEl = $('#apfCount');

      // Scheduler (finance calendar)
      const schedulerEl = $('#apfScheduler');
      const schedulerForm = $('#apfSchedulerForm');
      const schedulerDateInput = $('#apfSchedulerDate');
      const schedulerSelectedDisplay = $('#apfSchedulerSelectedDisplay');
      const schedulerTitleInput = $('#apfSchedulerTitleInput');
      const schedulerSearch = $('#apfSchedulerSearch');
      const schedulerProvidersBox = document.querySelector('.apf-scheduler__providers');
      const schedulerRecipientsHolder = $('#apfSchedulerRecipientsHolder');
      const MONTH_NAMES = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
      const WEEKDAYS = ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];

      if(schedulerEl && schedulerForm && schedulerProvidersBox){
        let state = {
          month: new Date(),
          selectedDate: '',
          selectedProviders: new Set(),
          events: [],
          providers: [],
          providerIndex: new Map(),
          eventsByDate: new Map(),
        };

        state.month.setDate(1);

        try{
          const rawEvents = JSON.parse(schedulerEl.getAttribute('data-events') || '[]');
          if(Array.isArray(rawEvents)){ state.events = rawEvents; }
        }catch(_e){}
        try{
          const rawProviders = JSON.parse(schedulerEl.getAttribute('data-providers') || '[]');
          if(Array.isArray(rawProviders)){ state.providers = rawProviders; }
        }catch(_e){}

        state.providers.forEach(provider=>{
          if(provider && provider.key){
            state.providerIndex.set(provider.key, provider);
          }
        });

        state.events.forEach(evt=>{
          if(!evt || !evt.date){ return; }
          const date = evt.date;
          if(!state.eventsByDate.has(date)){
            state.eventsByDate.set(date, []);
          }
          state.eventsByDate.get(date).push(evt);
        });

        function formatDateBr(value){
          if(!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)){ return value || '—'; }
          return value.slice(8,10) + '/' + value.slice(5,7) + '/' + value.slice(0,4);
        }

        function updateHiddenRecipients(){
          if(!schedulerRecipientsHolder){ return; }
          schedulerRecipientsHolder.innerHTML = '';
          state.selectedProviders.forEach(key=>{
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'apf_scheduler_recipients[]';
            input.value = key;
            schedulerRecipientsHolder.appendChild(input);
          });
        }

        function toggleProvider(key){
          if(!state.providerIndex.has(key)){ return; }
          if(state.selectedProviders.has(key)){
            state.selectedProviders.delete(key);
          }else{
            state.selectedProviders.add(key);
          }
          updateHiddenRecipients();
          renderProviders((schedulerSearch && schedulerSearch.value) || '');
        }

        function renderProviders(filter){
          if(!schedulerProvidersBox){ return; }
          const term = (filter || '').toLowerCase();
          schedulerProvidersBox.innerHTML = '';
          const filtered = state.providers.filter(provider=>{
            const blob = (provider.label + ' ' + (provider.email || '')).toLowerCase();
            return term ? blob.includes(term) : true;
          });
          if(!filtered.length){
            const emptyMsg = document.createElement('p');
            emptyMsg.className = 'apf-scheduler__providers-empty';
            emptyMsg.textContent = 'Nenhum prestador encontrado.';
            schedulerProvidersBox.appendChild(emptyMsg);
            return;
          }
          filtered.forEach(provider=>{
            if(!provider || !provider.key){ return; }
            const label = document.createElement('label');
            label.className = 'apf-scheduler__provider';
            if(state.selectedProviders.has(provider.key)){
              label.classList.add('is-selected');
            }
            label.setAttribute('data-key', provider.key);

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = state.selectedProviders.has(provider.key);
            checkbox.tabIndex = -1;

            const span = document.createElement('span');
            span.textContent = provider.label || provider.name || provider.email || '';

            label.appendChild(checkbox);
            label.appendChild(span);
            label.addEventListener('click', function(e){
              e.preventDefault();
              toggleProvider(provider.key);
            });
            schedulerProvidersBox.appendChild(label);
          });
        }

        function setSelectedDate(iso){
          state.selectedDate = iso;
          if(schedulerDateInput){
            schedulerDateInput.value = iso;
          }
          if(schedulerSelectedDisplay){
            schedulerSelectedDisplay.textContent = iso ? formatDateBr(iso) : 'Nenhum dia selecionado';
          }
          renderCalendar();
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
                marker.className = 'apf-scheduler__day-marker';
                button.appendChild(marker);
                button.title = state.eventsByDate.get(iso).map(evt=>evt.title).join(', ');
              }
              button.addEventListener('click', ()=>setSelectedDate(iso));
            }

            daysGrid.appendChild(button);
          }

          container.appendChild(header);
          container.appendChild(weekdaysRow);
          container.appendChild(daysGrid);
          schedulerEl.innerHTML = '';
          schedulerEl.appendChild(container);
        }

        renderCalendar();
        renderProviders('');

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

      let activeQuery = '';
      let activeFilterKey = '';

      function applyFilter(){
        const qn = norm(activeQuery);
        const qd = digits(activeQuery);
        const filterApplied = !!(activeQuery || activeFilterKey);
        let visible = 0;
        rows.forEach(row=>{
          const raw = row.getAttribute('data-search') || row.innerText || '';
          const n = norm(raw), d = digits(raw);
          const okText = qn ? n.includes(qn) : true;
          const okDig  = qd ? d.includes(qd) : true;
          const okDirector = activeFilterKey ? (row.getAttribute('data-director-key') === activeFilterKey) : true;
          const show = okText && okDig && okDirector;
          row.classList.toggle('apf-hide', !show);
          if(show){
            visible++; highlightRow(row, activeQuery);
          }else{
            highlightRow(row, '');
          }
        });
        countEl.textContent = filterApplied ? (visible + ' resultado(s)') : (rows.length + ' registro(s)');
      }

      function runSearch(){
        activeQuery = (input.value || '').trim();
        activeFilterKey = directorSelect ? directorSelect.value : '';
        applyFilter();
      }

      function clearFilter(){
        activeQuery = '';
        activeFilterKey = '';
        input.value = '';
        if(directorSelect){ directorSelect.value = ''; }
        applyFilter();
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
          const dd = document.createElement('dd'); dd.textContent = (data[k] ?? '—');
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
