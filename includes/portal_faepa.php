<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$apf_portal_faepa_cb = function () {
    if ( ! is_user_logged_in() ) {
        $redirect = isset( $_SERVER['REQUEST_URI'] ) ? esc_url( $_SERVER['REQUEST_URI'] ) : home_url();
        return apf_render_login_card( array(
            'redirect'    => $redirect,
            'title'       => 'Portal FAEPA',
            'description' => 'Faça login para ver todos os avisos programados para colaboradores e coordenadores.',
        ) );
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
                    'name'           => isset( $entry['provider_name'] ) ? sanitize_text_field( (string) $entry['provider_name'] ) : '',
                    'value'          => isset( $entry['provider_value'] ) ? sanitize_text_field( (string) $entry['provider_value'] ) : '',
                    'status'         => $status,
                    'status_label'   => $status_label,
                    'decision_label' => $decision_at ? date_i18n( 'd/m/Y H:i', $decision_at ) : '',
                    'note'           => isset( $entry['decision_note'] ) ? sanitize_textarea_field( $entry['decision_note'] ) : '',
                    'details'        => $details,
                );
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

      <div class="apf-faepa-calendar" id="apfFaepaCalendar" data-events="<?php echo $calendar_attr; ?>">
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
          <div class="apf-faepa-return__grid">
            <?php foreach ( $faepa_returns as $return ) :
                $counts = $return['counts'];
                $course = $return['coordinator']['course'] ?? '';
                $coord  = $return['coordinator']['name'] ?? '';
                $forwarded_label = $return['forwarded_label'];
            ?>
              <article class="apf-faepa-return__card">
                <header>
                  <div>
                    <h4><?php echo esc_html( $return['title'] ); ?></h4>
                    <?php if ( $forwarded_label ) : ?>
                      <small>Enviado pelo financeiro em <?php echo esc_html( $forwarded_label ); ?></small>
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
                  </div>
                  <div class="apf-faepa-return__chips">
                    <span class="apf-faepa-chip apf-faepa-chip--info"><?php echo esc_html( $counts['total'] . ' colaborador(es)' ); ?></span>
                  </div>
                </header>

                <details class="apf-faepa-return__details">
                  <summary>Ver colaboradores (<?php echo esc_html( $counts['total'] ); ?>)</summary>
                  <div class="apf-faepa-return__list">
                    <?php foreach ( $return['items'] as $item ) :
                        if ( isset( $item['status'] ) && 'approved' !== $item['status'] ) {
                            continue;
                        }
                        $detail_payment = $item['details']['payment'] ?? array();
                        $detail_service = $item['details']['service'] ?? array();
                        $detail_payout  = $item['details']['payout'] ?? array();
                    ?>
                      <article class="apf-faepa-entry">
                        <details>
                          <summary class="apf-faepa-entry__head">
                            <div>
                              <strong><?php echo esc_html( $item['name'] ?: 'Colaborador' ); ?></strong>
                              <?php if ( $item['value'] ) : ?>
                                <span class="apf-faepa-entry__value"><?php echo esc_html( $item['value'] ); ?></span>
                              <?php endif; ?>
                            </div>
                            <span class="apf-faepa-pill apf-faepa-pill--<?php echo esc_attr( $item['status'] ); ?>">
                              <?php echo esc_html( $item['status_label'] ); ?>
                            </span>
                          </summary>
                          <div class="apf-faepa-entry__body">
                            <ul class="apf-faepa-entry__meta">
                              <?php if ( $item['decision_label'] ) : ?>
                                <li><?php echo esc_html( $item['decision_label'] ); ?></li>
                              <?php endif; ?>
                              <?php if ( $item['note'] ) : ?>
                                <li>Observação: <?php echo esc_html( $item['note'] ); ?></li>
                              <?php endif; ?>
                            </ul>

                            <?php if ( ! empty( $detail_payment ) ) : ?>
                              <div class="apf-faepa-entry__block">
                                <strong>Dados do pagamento</strong>
                                <dl>
                                  <?php foreach ( $detail_payment as $label => $value ) : ?>
                                    <dt><?php echo esc_html( $label ); ?></dt>
                                    <dd><?php echo esc_html( $value === '' ? '—' : $value ); ?></dd>
                                  <?php endforeach; ?>
                                </dl>
                              </div>
                            <?php endif; ?>

                            <?php if ( ! empty( $detail_service ) ) : ?>
                              <div class="apf-faepa-entry__block">
                                <strong>Prestação do serviço</strong>
                                <dl>
                                  <?php foreach ( $detail_service as $label => $value ) : ?>
                                    <dt><?php echo esc_html( $label ); ?></dt>
                                    <dd><?php echo esc_html( $value === '' ? '—' : $value ); ?></dd>
                                  <?php endforeach; ?>
                                </dl>
                              </div>
                            <?php endif; ?>

                            <?php if ( ! empty( $detail_payout ) ) : ?>
                              <div class="apf-faepa-entry__block">
                                <strong>Dados para pagamento</strong>
                                <dl>
                                  <?php foreach ( $detail_payout as $label => $value ) : ?>
                                    <dt><?php echo esc_html( $label ); ?></dt>
                                    <dd><?php echo esc_html( $value === '' ? '—' : $value ); ?></dd>
                                  <?php endforeach; ?>
                                </dl>
                              </div>
                            <?php endif; ?>
                          </div>
                        </details>
                      </article>
                    <?php endforeach; ?>
                  </div>
                </details>
              </article>
            <?php endforeach; ?>
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

    <style>
      .apf-faepa{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:1080px;margin:28px auto;padding:0 12px;color:#0f172a}
      .apf-faepa__hero{background:linear-gradient(120deg,#0ea5e9,#075985);color:#e2f3ff;border-radius:16px;padding:22px 24px;box-shadow:0 16px 36px rgba(15,23,42,.18);margin-bottom:18px}
      .apf-faepa__eyebrow{margin:0 0 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:#e0f2fe}
      .apf-faepa__hero h2{margin:0;font-size:24px;line-height:1.2}
      .apf-faepa__lede{margin:8px 0 0;font-size:14px;max-width:720px;color:#d9edff}
      .apf-faepa-calendar{background:#fff;border:1px solid #e4e7ec;border-radius:16px;padding:16px;box-shadow:0 12px 28px rgba(15,23,42,.06)}
      .apf-faepa-calendar__body{display:flex;flex-direction:column;gap:12px}
      .apf-faepa-calendar__inner{display:flex;flex-direction:column;gap:12px}
      .apf-faepa-calendar__header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
      .apf-faepa-calendar__header h4{margin:0;font-size:18px;color:#0f172a}
      .apf-faepa-calendar__nav{display:flex;gap:8px}
      .apf-faepa-calendar__btn{width:34px;height:34px;border-radius:10px;border:1px solid #cfd4dc;background:#f8fafc;color:#0f172a;font-weight:700;cursor:pointer;transition:all .15s ease}
      .apf-faepa-calendar__btn:hover,
      .apf-faepa-calendar__btn:focus{border-color:#0ea5e9;outline:none;box-shadow:0 0 0 3px rgba(14,165,233,.18)}
      .apf-faepa-calendar__weekdays,
      .apf-faepa-calendar__days{display:grid;grid-template-columns:repeat(7,minmax(36px,1fr));gap:8px}
      .apf-faepa-calendar__weekday{text-align:center;font-size:12px;font-weight:700;color:#475467;text-transform:uppercase;letter-spacing:.02em}
      .apf-faepa-calendar__day{position:relative;height:54px;border-radius:12px;border:1px solid #e4e7ec;background:#f8fafc;color:#0f172a;font-size:15px;font-weight:700;display:flex;align-items:center;justify-content:center;transition:border-color .15s ease,background .15s ease;outline:none}
      .apf-faepa-calendar__day--muted{opacity:.35}
      .apf-faepa-calendar__day--has-event{border-color:#0ea5e9;background:linear-gradient(160deg,rgba(14,165,233,.12),rgba(59,130,246,.08))}
      .apf-faepa-calendar__day--group-coordinators{border-color:#1d4ed8}
      .apf-faepa-calendar__day--group-providers{border-color:#047857}
      .apf-faepa-calendar__day--group-mixed{border-color:#0b6b94}
      .apf-faepa-calendar__day--has-event:hover{cursor:pointer;box-shadow:0 12px 28px rgba(14,165,233,.18)}
      .apf-faepa-calendar__markers{position:absolute;bottom:6px;left:50%;transform:translateX(-50%);display:flex;gap:6px}
      .apf-faepa-calendar__marker{width:10px;height:10px;border-radius:999px;border:1px solid #fff;box-shadow:0 0 0 1px rgba(15,23,42,.12)}
      .apf-faepa-calendar__marker--providers{background:#0ea5e9}
      .apf-faepa-calendar__marker--coordinators{background:#1d4ed8}
      .apf-faepa-calendar__legend{margin-top:6px;display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:#475467}
      .apf-faepa-calendar__dot{display:inline-block;width:12px;height:12px;border-radius:999px;margin-right:6px;vertical-align:middle}
      .apf-faepa-calendar__dot--providers{background:#0ea5e9}
      .apf-faepa-calendar__dot--coordinators{background:#1d4ed8}
      .apf-faepa__empty{margin:12px 2px 0;font-size:14px;color:#b42318;font-weight:600}
      .apf-faepa__hint{margin:12px 2px 0;font-size:13px;color:#475467}
      .apf-faepa-return{margin-top:20px;border:1px solid #e4e7ec;border-radius:16px;padding:16px;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.06);display:flex;flex-direction:column;gap:14px}
      .apf-faepa-return__head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
      .apf-faepa-return__head h3{margin:4px 0 0;font-size:18px;color:#0f172a}
      .apf-faepa-return__head p{margin:6px 0 0;font-size:13px;color:#475467;max-width:720px}
      .apf-faepa-return__badge{border:1px solid #e4e7ec;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;color:#475467;background:#f8fafc}
      .apf-faepa-return__empty{margin:6px 0 0;font-size:13px;color:#b42318;font-weight:600}
      .apf-faepa-return__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}
      .apf-faepa-return__card{border:1px solid #e4e7ec;border-radius:14px;padding:14px;background:#f9fbff;display:flex;flex-direction:column;gap:10px;box-shadow:0 10px 20px rgba(15,23,42,.04)}
      .apf-faepa-return__card h4{margin:0;font-size:16px;color:#0f172a}
      .apf-faepa-return__card small{display:block;margin-top:2px;font-size:12px;color:#475467}
      .apf-faepa-return__meta{margin:6px 0 0;font-size:12px;color:#475467}
      .apf-faepa-return__chips{display:flex;flex-wrap:wrap;gap:6px}
      .apf-faepa-chip{padding:4px 8px;border-radius:10px;font-size:12px;font-weight:700}
      .apf-faepa-chip--info{background:rgba(14,165,233,.16);color:#075985;border:1px solid rgba(14,165,233,.3)}
      .apf-faepa-return__note{margin:0;font-size:13px;color:#0f172a;background:#e0f2fe;border:1px solid #bfdbfe;border-radius:10px;padding:10px 12px}
      .apf-faepa-return__details summary{cursor:pointer;font-weight:700;color:#0f172a}
      .apf-faepa-return__list{display:flex;flex-direction:column;gap:10px;margin-top:10px}
      .apf-faepa-entry{border:1px solid #e4e7ec;border-radius:12px;padding:12px;background:#fff;display:flex;flex-direction:column;gap:8px}
      .apf-faepa-entry details summary{cursor:pointer;list-style:none}
      .apf-faepa-entry details summary::-webkit-details-marker{display:none}
      .apf-faepa-entry__head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:13px}
      .apf-faepa-entry__head strong{font-size:13px}
      .apf-faepa-entry__value{margin-left:8px;font-weight:600;color:#0ea5e9;font-size:12px}
      .apf-faepa-pill{padding:4px 8px;border-radius:10px;font-size:12px;font-weight:700;background:#e5e7eb;color:#0f172a}
      .apf-faepa-pill--approved{background:rgba(16,185,129,.18);color:#047857}
      .apf-faepa-pill--rejected{background:rgba(248,113,113,.22);color:#991b1b}
      .apf-faepa-pill--pending{background:rgba(251,191,36,.22);color:#92400e}
      .apf-faepa-entry__meta{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:8px;font-size:12px;color:#475467}
      .apf-faepa-entry__block{border-top:1px dashed #e4e7ec;padding-top:8px;margin-top:4px}
      .apf-faepa-entry__block dl{display:grid;grid-template-columns: minmax(140px,1fr) 2fr;gap:6px 12px;margin:6px 0 0}
      .apf-faepa-entry__block dt{font-size:12px;color:#475467}
      .apf-faepa-entry__block dd{margin:0;font-size:13px;color:#0f172a;word-break:break-word}
      .apf-faepa-entry__admin{font-size:13px;color:#0f172a;font-weight:700;text-decoration:none;margin-top:6px;display:inline-flex;gap:6px;align-items:center}
      .apf-faepa-entry__admin:hover{text-decoration:underline}
      .apf-faepa-modal{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:20px;z-index:2000;opacity:0;pointer-events:none;transition:opacity .18s ease}
      .apf-faepa-modal[aria-hidden="false"]{opacity:1;pointer-events:auto}
      .apf-faepa-modal__overlay{position:absolute;inset:0;background:rgba(15,23,42,.48)}
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
      @media(max-width:720px){
        .apf-faepa-calendar__weekdays,
        .apf-faepa-calendar__days{grid-template-columns:repeat(7,minmax(30px,1fr))}
        .apf-faepa-calendar__day{height:48px;font-size:14px}
        .apf-faepa-modal__dialog{padding:16px}
      }
      @media(max-width:540px){
        .apf-faepa-calendar__weekdays,
        .apf-faepa-calendar__days{grid-template-columns:repeat(7,minmax(26px,1fr))}
        .apf-faepa-calendar__day{height:44px}
        .apf-faepa-modal{padding:12px}
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

      const MONTH_NAMES = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
      const WEEKDAYS = ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];
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
        const info = eventsByDate.get(date);
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
        const firstWeekday = (monthDate.getDay() + 6) % 7;
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
              const info = eventsByDate.get(iso);
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

      renderCalendar();
    })();
    </script>
    <?php
    return ob_get_clean();
};

add_shortcode( 'apf_portal_faepa', $apf_portal_faepa_cb );
add_shortcode( 'portal_faepa', $apf_portal_faepa_cb );
