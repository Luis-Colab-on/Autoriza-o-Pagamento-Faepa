<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
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
        $coordinator_key = apf_inbox_build_director_key( $existing_entry['director'] ?? '', $existing_entry['course'] ?? '' );
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
            $title = isset( $event['title'] ) ? sanitize_text_field( $event['title'] ) : '';
            if ( '' === $title ) {
                continue;
            }
            if ( empty( $event['recipients'] ) || ! is_array( $event['recipients'] ) ) {
                continue;
            }

            $matched_providers   = false;
            $matched_coordinators = false;

            foreach ( $event['recipients'] as $recipient ) {
                if ( ! is_array( $recipient ) ) {
                    continue;
                }
                $recipient_group = isset( $recipient['group'] ) ? sanitize_key( $recipient['group'] ) : '';
                if ( ! in_array( $recipient_group, array( 'providers', 'coordinators' ), true ) ) {
                    $recipient_group = 'providers';
                }
                $recipient_user  = isset( $recipient['user_id'] ) ? (int) $recipient['user_id'] : 0;
                $recipient_email = isset( $recipient['email'] ) ? strtolower( sanitize_email( $recipient['email'] ) ) : '';

                if ( 'coordinators' === $recipient_group ) {
                    if ( ( $recipient_user > 0 && $recipient_user === $user_id ) || ( $coord_email_lower && $recipient_email === $coord_email_lower ) ) {
                        $matched_coordinators = true;
                    }
                    continue;
                }

                if ( 'providers' === $recipient_group ) {
                    $dir_matches = false;
                    if ( $coordinator_key && ! empty( $recipient['director_key'] ) ) {
                        $recipient_dir_key = sanitize_text_field( $recipient['director_key'] );
                        if ( $recipient_dir_key === $coordinator_key ) {
                            $dir_matches = true;
                        }
                    }

                    if ( $dir_matches || ( $coord_email_lower && $recipient_email === $coord_email_lower ) ) {
                        $matched_providers = true;
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
                );
            }

            if ( $matched_providers ) {
                $calendar_events_map[ $date ]['titles']['providers'][] = $title;
            }
            if ( $matched_coordinators ) {
                $calendar_events_map[ $date ]['titles']['coordinators'][] = $title;
            }
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

            $calendar_events[] = array(
                'date'   => $date,
                'groups' => $groups,
                'titles' => array(
                    'providers'    => $providers_titles,
                    'coordinators' => $coordinator_titles,
                ),
            );
        }

        if ( ! empty( $calendar_events ) ) {
            usort( $calendar_events, function( $a, $b ) {
                return strcmp( $a['date'], $b['date'] );
            } );
        }
    }

    $coord_calendar_attr = esc_attr( wp_json_encode( $calendar_events, JSON_UNESCAPED_UNICODE ) );

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

          <div class="apf-coord-calendar" id="apfCoordCalendar" data-events="<?php echo $coord_calendar_attr; ?>">
            <div class="apf-coord-calendar__body"></div>
          </div>
          <?php if ( empty( $calendar_events ) ) : ?>
            <p class="apf-coord-calendar__empty">Nenhum aviso programado até o momento.</p>
          <?php else : ?>
            <p class="apf-coord-calendar__hint">Use as setas para navegar e veja os dias destacados com avisos do financeiro.</p>
          <?php endif; ?>
        <?php elseif ( $blocked_message ) : ?>
          <div class="apf-coord-blocked">
            <p><?php echo esc_html( $blocked_message ); ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <style>
      .apf-coord-card{
        max-width:520px;
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
      .apf-coord-calendar{
        margin-top:16px;
        border:1px solid #e4e7ec;
        border-radius:16px;
        background:#f7f8fb;
        padding:16px;
      }
      .apf-coord-calendar__body{
        display:flex;
        flex-direction:column;
        gap:12px;
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
      .apf-coord-calendar__day--muted{
        opacity:.35;
      }
      .apf-coord-calendar__day--has-event{
        border-color:#1f6feb;
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
      .apf-coord-calendar__empty,
      .apf-coord-calendar__hint{
        font-size:13px;
        color:#475467;
        margin:8px 0 0;
      }
      @media(max-width:640px){
        .apf-coord-card{
          margin:16px;
          padding:20px;
        }
        .apf-coord-calendar__weekdays,
        .apf-coord-calendar__days{
          grid-template-columns:repeat(7,minmax(28px,1fr));
        }
      }
    </style>
    <script>
    (function(){
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
          ['apf_coord_notice','apf_coord_status'].forEach(param=>{
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

      const calendarNode = document.getElementById('apfCoordCalendar');
      if(calendarNode){
        const MONTH_NAMES = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
        const WEEKDAYS = ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];
        let monthDate = new Date();
        monthDate.setDate(1);

        const eventsByDate = new Map();
        const groupLabels = {
          providers: 'Colaboradores',
          coordinators: 'Coordenadores',
        };

        try{
          const raw = JSON.parse(calendarNode.getAttribute('data-events') || '[]');
          if(Array.isArray(raw)){
            raw.forEach(evt=>{
              if(!evt || !evt.date){ return; }
              const date = evt.date;
              const groups = Array.isArray(evt.groups) ? evt.groups : [];
              const normalizedGroups = Array.from(
                new Set(
                  groups
                    .map(group => (group || '').toString().toLowerCase())
                    .filter(group => group === 'providers' || group === 'coordinators')
                )
              );
              const titles = {};
              if(evt.titles && typeof evt.titles === 'object'){
                Object.keys(evt.titles).forEach(key=>{
                  const normalizedKey = (key || '').toString().toLowerCase();
                  if(normalizedKey !== 'providers' && normalizedKey !== 'coordinators'){ return; }
                  const list = Array.isArray(evt.titles[key]) ? evt.titles[key] : [];
                  titles[normalizedKey] = list.filter(item => typeof item === 'string' && item.trim() !== '');
                });
              }

              const existing = eventsByDate.get(date) || { date, groups: [], titles: {} };
              const mergedGroups = new Set([ ...(existing.groups || []), ...(normalizedGroups.length ? normalizedGroups : ['providers']) ]);
              existing.groups = Array.from(mergedGroups);
              existing.titles = existing.titles || {};
              Object.keys(titles).forEach(group=>{
                const prev = Array.isArray(existing.titles[group]) ? existing.titles[group] : [];
                existing.titles[group] = prev.concat(titles[group]);
              });
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
              div.textContent = String(dayNumber);
              const iso = year + '-' + String(monthIndex + 1).padStart(2,'0') + '-' + String(dayNumber).padStart(2,'0');
              if(eventsByDate.has(iso)){
                const info = eventsByDate.get(iso);
                div.classList.add('apf-coord-calendar__day--has-event');
                const markers = document.createElement('div');
                markers.className = 'apf-coord-calendar__markers';
                (info.groups || []).forEach(group=>{
                  const safeGroup = (group === 'coordinators') ? 'coordinators' : 'providers';
                  const marker = document.createElement('span');
                  marker.className = 'apf-coord-calendar__marker apf-coord-calendar__marker--' + safeGroup;
                  marker.title = groupLabels[safeGroup] || '';
                  markers.appendChild(marker);
                });
                div.appendChild(markers);
                const tooltip = buildTooltip(info);
                if(tooltip){
                  div.title = tooltip;
                }
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
