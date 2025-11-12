<?php
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Shortcode: [apf_portal form_url="/enviar-solicitacao"]
 * – Portal do prestador com EDIÇÃO via POST (sem AJAX, sem modal)
 * – Puxa dados do Admin (CPT apf_submission) e atualiza os mesmos metadados
 */
add_shortcode('apf_portal', function($atts){
    $a = shortcode_atts([
        'form_url' => '',
    ], $atts, 'apf_portal');

    if ( ! is_user_logged_in() ) {
        $redirect = isset($_SERVER['REQUEST_URI']) ? esc_url( $_SERVER['REQUEST_URI'] ) : home_url();
        return apf_render_login_card(array(
            'redirect'    => $redirect,
            'title'       => 'Faça login para acessar o portal',
            'description' => 'Sua solicitação e histórico ficam salvos na conta. Entre para atualizar seus dados ou criar uma nova solicitação.',
        ));
    }

    $user       = wp_get_current_user();
    $user_id    = get_current_user_id();
    $user_email = $user && ! empty($user->user_email) ? sanitize_email($user->user_email) : '';
    $collab_alias_email = apf_get_user_channel_email( $user_id, 'collab' );

    if ( function_exists( 'apf_get_directors_list' ) ) {
        $apf_directors = apf_get_directors_list();
    } else {
        $apf_directors = get_option('apf_directors', array());
        if ( ! is_array($apf_directors) ) {
            $apf_directors = array();
        }
    }
    $apf_directors = apf_filter_approved_directors( $apf_directors );
    if ( ! empty($apf_directors) ) {
        usort($apf_directors, function( $a, $b ){
            $course_a = $a['course'] ?? '';
            $course_b = $b['course'] ?? '';
            $by_course = strcasecmp($course_a, $course_b);
            if ( $by_course !== 0 ) {
                return $by_course;
            }
            return strcasecmp($a['director'] ?? '', $b['director'] ?? '');
        });
    }
    $apf_director_names = array();
    foreach ( $apf_directors as $entry ) {
        if ( isset($entry['director']) ) {
            $name = trim((string) $entry['director']);
            if ( $name !== '' ) {
                $apf_director_names[$name] = true;
            }
        }
    }

    // ====== PROCESSA SALVAMENTO (POST normal) ======
    if ( ! empty($_POST['apf_portal_submit']) ) {
        if ( ! isset($_POST['apf_portal_nonce']) || ! wp_verify_nonce($_POST['apf_portal_nonce'], 'apf_portal_save') ) {
            return '<div style="max-width:720px;margin:16px auto;padding:12px;border:1px solid #fecaca;border-radius:10px;background:#fff5f5;color:#991b1b">Falha de segurança. Recarregue a página.</div>';
        }

        // helpers
        $only_num = function($v){ return preg_replace('/\D+/', '', (string) wp_unslash($v) ); };
        $clean    = function($v){ return sanitize_text_field( wp_unslash($v) ); };

        // coleta e normaliza
        $email_prest    = sanitize_email( wp_unslash($_POST['email_prest'] ?? '') );
        if ( empty($email_prest) ) $email_prest = $user_email;
        if ( $email_prest ) {
            apf_set_user_channel_email( $user_id, 'collab', $email_prest );
        }

        $valor_bruto    = wp_unslash($_POST['valor'] ?? '');
        $valor_norm     = apf_normalize_currency_value( $valor_bruto );

        $pessoa_tipo    = ( isset($_POST['pessoa_tipo']) && $_POST['pessoa_tipo']==='pf' ) ? 'pf' : 'pj';

        $payload = [
            // passo 1
            'nome_diretor'   => $clean($_POST['nome_diretor'] ?? ''),
            'num_controle'   => $only_num($_POST['num_controle'] ?? ''),
            'tel_prestador'  => $only_num($_POST['tel_prestador'] ?? ''),
            'email_prest'    => $email_prest,
            'num_doc_fiscal' => $clean($_POST['num_doc_fiscal'] ?? ''),
            'valor'          => $valor_norm,
            'pessoa_tipo'    => $pessoa_tipo,
            'nome_empresa'   => $clean($_POST['nome_empresa'] ?? ''),
            'cnpj'           => $only_num($_POST['cnpj'] ?? ''),
            'nome_prof'      => $clean($_POST['nome_prof'] ?? ''),
            'cpf'            => $only_num($_POST['cpf'] ?? ''),
            // passo 2
            'prest_contas'   => $clean($_POST['prest_contas'] ?? ''),
            'data_prest'     => $clean($_POST['data_prest'] ?? ''),
            'classificacao'  => $clean($_POST['classificacao'] ?? ''),
            'descricao'      => sanitize_textarea_field( wp_unslash($_POST['descricao'] ?? '') ),
            'nome_curto'     => $clean($_POST['nome_curto'] ?? ''),
            'carga_horaria'  => $only_num($_POST['carga_horaria'] ?? ''),
            // passo 3
            'banco'          => $clean($_POST['banco'] ?? ''),
            'agencia'        => $only_num($_POST['agencia'] ?? ''),
            'conta'          => $only_num($_POST['conta'] ?? ''),
        ];

        // ====== cria sempre um novo registro ======
        $titulo = 'Solicitação - ' . (
            $pessoa_tipo === 'pj'
                ? ( $payload['nome_empresa'] ?: 'PJ' )
                : ( $payload['nome_prof'] ?: 'PF' )
        ) . ' - ' . current_time('Y-m-d H:i');

        $post_args = [
            'post_type'     => 'apf_submission',
            'post_title'    => $titulo,
            'post_status'   => 'publish',
            'post_author'   => $user_id,
            'post_date'     => current_time('mysql'),
            'post_date_gmt' => get_gmt_from_date( current_time('mysql') ),
        ];

        $post_id = wp_insert_post( $post_args );

        if ( $post_id && ! is_wp_error( $post_id ) ) {
            foreach ( $payload as $k => $v ) {
                update_post_meta( $post_id, 'apf_' . $k, $v );
            }

            wp_safe_redirect( add_query_arg( 'apf_updated', '1', get_permalink() ) . '#apf-portal-root' );
            exit;
        }

        return '<div style="max-width:720px;margin:16px auto;padding:12px;border:1px solid #fecaca;border-radius:10px;background:#fff5f5;color:#991b1b">Não foi possível salvar agora.</div>';
    }

    // ====== CARREGA O REGISTRO PARA PRÉ-PREENCHER (puxa do Admin) ======
    $current_id = function_exists('apf_get_user_submission_id') ? apf_get_user_submission_id( $user_id, $user_email ) : 0;

    // fallback por e-mail (se ainda não estiver vinculado ao autor)
    if ( ! $current_id && $user_email ) {
        $qf = new WP_Query([
            'post_type'      => 'apf_submission',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [[
                'key'     => 'apf_email_prest',
                'value'   => $user_email,
                'compare' => 'LIKE',
            ]],
            'fields' => 'ids',
        ]);
        if ( $qf->have_posts() && ! empty($qf->posts[0]) ) {
            $current_id = (int)$qf->posts[0];
            // vincula ao autor atual
            wp_update_post(['ID'=>$current_id,'post_author'=>$user_id]);
        }
    }

    $g = function($k) use ($current_id){ return $current_id ? get_post_meta($current_id, 'apf_'.$k, true) : ''; };

    $saved_contact_email = $g('email_prest');
    $portal_contact_email = $collab_alias_email ?: $saved_contact_email;
    if ( '' === $portal_contact_email ) {
        $portal_contact_email = $user_email;
    }

    $calendar_events = array();
    if ( function_exists( 'apf_scheduler_get_events' ) ) {
        $events = apf_scheduler_get_events();
        $user_email_lower = $portal_contact_email ? strtolower( $portal_contact_email ) : '';
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

            $visible = false;
            foreach ( $event['recipients'] as $recipient ) {
                if ( ! is_array( $recipient ) ) {
                    continue;
                }
                $recipient_group = isset( $recipient['group'] ) ? sanitize_key( $recipient['group'] ) : '';
                if ( ! in_array( $recipient_group, array( 'providers', 'coordinators' ), true ) ) {
                    $recipient_group = 'providers';
                }
                if ( 'coordinators' === $recipient_group ) {
                    continue;
                }
                $recipient_group = isset( $recipient['group'] ) ? sanitize_key( $recipient['group'] ) : '';
                if ( ! in_array( $recipient_group, array( 'providers', 'coordinators' ), true ) ) {
                    $recipient_group = 'providers';
                }
                if ( 'coordinators' === $recipient_group ) {
                    continue;
                }
                $recipient_user  = isset( $recipient['user_id'] ) ? (int) $recipient['user_id'] : 0;
                $recipient_email = isset( $recipient['email'] ) ? strtolower( sanitize_email( $recipient['email'] ) ) : '';
                if ( $recipient_user > 0 && $recipient_user === $user_id ) {
                    $visible = true;
                    break;
                }
                if ( $user_email_lower && $recipient_email === $user_email_lower ) {
                    $visible = true;
                    break;
                }
            }

            if ( ! $visible ) {
                continue;
            }

            $calendar_events[] = array(
                'date'  => $date,
                'title' => $title,
            );
        }
    }

    $portal_calendar_attr = esc_attr( wp_json_encode( $calendar_events, JSON_UNESCAPED_UNICODE ) );

    // “Meu último envio” (autor atual) – só para exibir um resumo
    $q = new WP_Query([
        'post_type'      => 'apf_submission',
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => 1,
        'author'         => $user_id,
    ]);

    ob_start(); ?>
    <div id="apf-portal-root" class="apf-portal" style="font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial">
      <?php if ( isset($_GET['apf_updated']) && $_GET['apf_updated']=='1' ): ?>
        <div class="apf-notice apf-notice-success">Dados atualizados com sucesso.</div>
      <?php endif; ?>

      <div class="apf-hero">
        <div>
          <h2>Portal do Prestador</h2>
          <p>Olá, <strong><?php echo esc_html($user->display_name ?: $user->user_login); ?></strong>. Inicie uma nova solicitação ou atualize seus dados.</p>
        </div>
        <div class="apf-actions">
          <?php if ($a['form_url']): ?>
            <a class="apf-btn apf-primary" href="<?php echo esc_url($a['form_url']); ?>">Abrir formulário</a>
          <?php endif; ?>
          <a class="apf-btn" href="#apf-edit">Editar meus dados</a>
        </div>
      </div>

      <div class="apf-panel" style="margin-top:16px">
        <h3 style="margin:0 0 8px">Meu último envio</h3>
        <div class="apf-table-wrap">
          <table class="apf-table">
            <thead><tr><th>Data</th><th>Tipo</th><th>Nome/Empresa</th><th>Valor (R$)</th><th>Curso</th></tr></thead>
            <tbody>
            <?php if ($q->have_posts()): while($q->have_posts()): $q->the_post();
              $row_id = get_the_ID();
              $tipo   = get_post_meta($row_id,'apf_pessoa_tipo',true);
              $nome   = ($tipo==='pj') ? get_post_meta($row_id,'apf_nome_empresa',true) : get_post_meta($row_id,'apf_nome_prof',true);
              $valor  = get_post_meta($row_id,'apf_valor',true);
              $valor_fmt = ($valor!=='') ? number_format((float)$valor,2,',','.') : '—';
              $curso  = get_post_meta($row_id,'apf_nome_curto',true);
            ?>
              <tr>
                <td><?php echo esc_html(get_the_date('d/m/Y H:i')); ?></td>
                <td style="text-transform:uppercase;"><?php echo esc_html($tipo ?: '—'); ?></td>
                <td><?php echo esc_html($nome ?: '—'); ?></td>
                <td><?php echo esc_html($valor_fmt); ?></td>
                <td><?php echo esc_html($curso ?: '—'); ?></td>
              </tr>
            <?php endwhile; wp_reset_postdata(); else: ?>
              <tr><td colspan="5">Você ainda não enviou nenhuma solicitação.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="apf-panel" style="margin-top:16px">
        <h3 style="margin:0 0 8px">Agenda financeira</h3>
        <div class="apf-portal-calendar" id="apfPortalCalendar" data-events="<?php echo $portal_calendar_attr; ?>">
          <div class="apf-portal-calendar__body"></div>
        </div>
        <?php if ( empty( $calendar_events ) ) : ?>
          <p class="apf-portal-calendar__empty">Nenhum aviso programado até o momento.</p>
        <?php else : ?>
          <p class="apf-portal-calendar__hint">Passe o mouse sobre os dias destacados para visualizar o aviso.</p>
        <?php endif; ?>
      </div>

      <!-- ====== FORMULÁRIO DE EDIÇÃO (3 abas), PRÉ-PREENCHIDO COM O QUE ESTÁ NO ADMIN ====== -->
      <?php $tipo_now = $g('pessoa_tipo') ?: 'pj'; ?>
      <div class="apf-panel" id="apf-edit" style="margin-top:16px">
        <h3 style="margin:0 0 8px">Editar meus dados</h3>

        <form method="post" id="apfEditForm" novalidate>
          <?php wp_nonce_field('apf_portal_save', 'apf_portal_nonce'); ?>
          <input type="hidden" name="apf_portal_submit" value="1">

          <div class="apf-tabs">
            <button type="button" class="is-active" data-tab="qe1">1. Informações do Pagamento</button>
            <button type="button" data-tab="qe2">2. Prestação do serviço</button>
            <button type="button" data-tab="qe3">3. Dados para pagamento</button>
          </div>

          <section class="apf-pane is-active" id="qe1">
            <div class="apf-grid">
              <label>Nome Completo Coordenador Executivo
                <?php $current_dir = $g('nome_diretor'); ?>
                <?php if ( ! empty($apf_directors) ) : ?>
                  <select name="nome_diretor">
                    <option value="" <?php echo selected($current_dir, '', false); ?>>Selecione um coordenador</option>
                    <?php foreach ( $apf_directors as $dir_entry ) :
                        $dir_name = isset($dir_entry['director']) ? trim((string) $dir_entry['director']) : '';
                        if ( $dir_name === '' ) { continue; }
                        $dir_course = isset($dir_entry['course']) ? trim((string) $dir_entry['course']) : '';
                        $label = $dir_course ? $dir_name . ' — ' . $dir_course : $dir_name;
                        $selected = selected($current_dir, $dir_name, false);
                    ?>
                      <option value="<?php echo esc_attr($dir_name); ?>" data-course="<?php echo esc_attr($dir_course); ?>" data-full-label="<?php echo esc_attr($label); ?>" data-coordinator-label="<?php echo esc_attr($dir_name); ?>" <?php echo $selected; ?>>
                        <?php echo esc_html($label); ?>
                      </option>
                    <?php endforeach; ?>
                    <?php if ( $current_dir !== '' && empty($apf_director_names[$current_dir]) ) : ?>
                      <option value="<?php echo esc_attr($current_dir); ?>" data-course="" data-full-label="<?php echo esc_attr($current_dir . ' (manual)'); ?>" data-coordinator-label="<?php echo esc_attr($current_dir); ?>" selected>
                        <?php echo esc_html($current_dir . ' (manual)'); ?>
                      </option>
                    <?php endif; ?>
                  </select>
                <?php else : ?>
                  <input type="text" name="nome_diretor" value="<?php echo esc_attr($current_dir); ?>">
                  <span class="apf-field-note">Cadastre coordenadores no dashboard financeiro para habilitar a seleção.</span>
                <?php endif; ?>
              </label>

              <label>Número de Controle Secretaria
                <input type="text" name="num_controle" inputmode="numeric" maxlength="20" value="<?php echo esc_attr($g('num_controle')); ?>">
              </label>

              <label>Telefone do Prestador
                <input type="text" name="tel_prestador" placeholder="(00) 00000-0000" value="<?php echo esc_attr($g('tel_prestador')); ?>">
              </label>

              <label>E-mail do Prestador
                <input type="email" name="email_prest" value="<?php echo esc_attr( $portal_contact_email ); ?>">
              </label>

              <label>Número do Documento Fiscal
                <input type="text" name="num_doc_fiscal" value="<?php echo esc_attr($g('num_doc_fiscal')); ?>">
              </label>

              <label>Valor (R$)
                <input type="text" name="valor" placeholder="0,00" value="<?php echo esc_attr( apf_format_currency_for_input( $g('valor') ) ); ?>">
              </label>
            </div>

            <fieldset class="apf-row">
              <legend>Pessoa</legend>
              <label class="apf-radio">
                <input type="radio" name="pessoa_tipo" value="pj" <?php checked($tipo_now,'pj'); ?>> Pessoa Jurídica (PJ)
              </label>
              <label class="apf-radio">
                <input type="radio" name="pessoa_tipo" value="pf" <?php checked($tipo_now,'pf'); ?>> Pessoa Física (PF)
              </label>
            </fieldset>

            <div class="apf-grid" id="apfPJ" style="<?php echo ($tipo_now==='pf'?'display:none':''); ?>">
              <label>Nome da Empresa
                <input type="text" name="nome_empresa" value="<?php echo esc_attr($g('nome_empresa')); ?>">
              </label>
              <label>CNPJ
                <input type="text" name="cnpj" placeholder="00.000.000/0000-00" value="<?php echo esc_attr($g('cnpj')); ?>">
              </label>
            </div>

            <div class="apf-grid" id="apfPF" style="<?php echo ($tipo_now==='pf'?'':'display:none'); ?>">
              <label>Nome do Profissional
                <input type="text" name="nome_prof" value="<?php echo esc_attr($g('nome_prof')); ?>">
              </label>
              <label>CPF
                <input type="text" name="cpf" placeholder="000.000.000-00" value="<?php echo esc_attr($g('cpf')); ?>">
              </label>
            </div>

            <div class="apf-actions">
              <button type="button" class="apf-next">Próximo</button>
            </div>
          </section>

          <section class="apf-pane" id="qe2">
            <div class="apf-grid">
              <label>Prestação de contas
                <input type="text" name="prest_contas" value="<?php echo esc_attr($g('prest_contas')); ?>">
              </label>

              <label>Data de prestação de serviço
                <input type="date" name="data_prest" value="<?php echo esc_attr($g('data_prest')); ?>">
              </label>

              <label>Classificação
                <input type="text" name="classificacao" value="<?php echo esc_attr($g('classificacao')); ?>">
              </label>

              <label>Descrição do serviço ou material
                <textarea name="descricao" rows="3"><?php echo esc_textarea($g('descricao')); ?></textarea>
              </label>

              <label>Nome curto do curso
                <input type="text" name="nome_curto" value="<?php echo esc_attr($g('nome_curto')); ?>">
              </label>

              <label>Carga horária do curso
                <input type="text" name="carga_horaria" inputmode="numeric" maxlength="5" placeholder="ex: 8" value="<?php echo esc_attr($g('carga_horaria')); ?>">
              </label>
            </div>

            <div class="apf-actions">
              <button type="button" class="apf-prev">Voltar</button>
              <button type="button" class="apf-next">Próximo</button>
            </div>
          </section>

          <section class="apf-pane" id="qe3">
            <div class="apf-grid">
              <label>Banco
                <input type="text" name="banco" value="<?php echo esc_attr($g('banco')); ?>">
              </label>
              <label>Agência
                <input type="text" name="agencia" inputmode="numeric" maxlength="10" placeholder="0000-0" value="<?php echo esc_attr($g('agencia')); ?>">
              </label>
              <label>Conta Corrente
                <input type="text" name="conta" inputmode="numeric" maxlength="20" placeholder="000000-0" value="<?php echo esc_attr($g('conta')); ?>">
              </label>
            </div>

            <div class="apf-actions">
              <button type="button" class="apf-prev">Voltar</button>
              <button type="submit" class="apf-submit">Salvar</button>
            </div>
          </section>
        </form>
      </div>
    </div>

    <style>
      .apf-notice{margin:10px 0;padding:10px 12px;border-radius:10px}
      .apf-notice-success{background:#f0f9ff;border:1px solid #b6e0fe;color:#055160}

      .apf-hero{
        display:flex;
        flex-wrap:wrap;
        gap:18px;
        justify-content:space-between;
        align-items:center;
        background:#fff;
        border:1px solid #e6e9ef;
        border-radius:12px;
        padding:18px 20px;
        box-shadow:0 8px 24px rgba(0,0,0,.04);
      }
      .apf-hero > div:first-child{flex:1 1 260px;min-width:0}
      .apf-hero .apf-actions{flex:0 0 auto;justify-content:flex-end}
      .apf-actions{display:flex;gap:10px;flex-wrap:wrap}
      .apf-btn{background:#eef2f7;color:#344054;text-decoration:none;border:none;border-radius:10px;padding:10px 14px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-weight:600;min-height:44px}
      .apf-btn.apf-primary{background:#1f6feb;color:#fff}
      .apf-panel{background:#fff;border:1px solid #e6e9ef;border-radius:12px;padding:14px 16px}
      .apf-table-wrap{overflow:auto;border:1px solid #e6e9ef;border-radius:12px}
      .apf-table{width:100%;min-width:640px;border-collapse:collapse}
      .apf-table thead th{background:#f7f8fb;text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef;font-weight:600;color:#475467}
      .apf-table tbody td{padding:10px 12px;border-bottom:1px solid #f0f2f5}
      .apf-tabs{display:flex;gap:8px;padding:8px 0 10px}
      .apf-tabs button{border:1px solid #e6e9ef;background:#f7f8fb;border-radius:10px;padding:8px 10px;cursor:pointer;flex:1 1 auto;min-width:140px}
      .apf-tabs button.is-active{background:#e8f1ff;border-color:#bcd4ff;color:#1849a9;font-weight:600}
      .apf-pane{display:none}
      .apf-pane.is-active{display:block}
      .apf-grid{
        display:grid;
        grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
        gap:clamp(10px,2vw,18px);
      }
      .apf-grid label{display:flex;flex-direction:column;font-size:13px;color:#344054;gap:6px}
      .apf-grid input,
      .apf-grid textarea,
      .apf-grid select{
        border:1px solid #d0d5dd;
        border-radius:10px;
        padding:10px 12px;
        font-size:14px;
        background:#fff;
        color:#344054;
      }
      .apf-grid select{
        appearance:none;
        -webkit-appearance:none;
        background-image:url('data:image/svg+xml;utf8,<svg fill="none" stroke="%23475067" stroke-width="1.5" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg>');
        background-position:calc(100% - 12px) 50%;
        background-repeat:no-repeat;
        padding-right:36px;
      }
      .apf-row{display:flex;gap:16px;border:none;margin:8px 0 0;padding:0;flex-wrap:wrap}
      .apf-radio{display:flex;align-items:center;gap:6px}
      .apf-panel .apf-actions{justify-content:space-between;margin-top:16px}
      .apf-panel .apf-actions button{
        background:#1f6feb;
        color:#fff;
        border:none;
        border-radius:10px;
        padding:10px 14px;
        cursor:pointer;
        min-height:44px;
        flex:1 1 160px;
      }
      .apf-panel .apf-actions .apf-prev{background:#a0a7b4}
      .apf-field-note{display:block;font-size:12px;color:#b42318;margin-top:6px}
      .apf-portal-calendar{border:1px solid #e6e9ef;border-radius:12px;background:#f7f8fb;padding:16px;margin-top:8px}
      .apf-portal-calendar__body{display:flex;flex-direction:column;gap:12px}
      .apf-portal-calendar__inner{display:flex;flex-direction:column;gap:12px}
      .apf-portal-calendar__header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
      .apf-portal-calendar__header h4{margin:0;font-size:16px;font-weight:600;color:#1d2939}
      .apf-portal-calendar__nav{display:flex;align-items:center;gap:8px}
      .apf-portal-calendar__btn{width:32px;height:32px;border-radius:50%;border:1px solid #d0d5dd;background:#fff;color:#1d2939;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s ease,border-color .15s ease}
      .apf-portal-calendar__btn:hover,
      .apf-portal-calendar__btn:focus{border-color:#1f6feb;color:#1f6feb;outline:none}
      .apf-portal-calendar__weekdays,
      .apf-portal-calendar__days{display:grid;grid-template-columns:repeat(7,minmax(32px,1fr));gap:6px}
      .apf-portal-calendar__weekday{text-align:center;font-size:12px;font-weight:600;color:#5f6b7a;text-transform:uppercase}
      .apf-portal-calendar__day{position:relative;height:44px;border-radius:10px;border:1px solid #d0d5dd;background:#fff;color:#1d2939;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center}
      .apf-portal-calendar__day--muted{opacity:.35}
      .apf-portal-calendar__day--has-event{border-color:#1f6feb;background:rgba(31,111,235,.12)}
      .apf-portal-calendar__tooltip{position:absolute;bottom:110%;left:50%;transform:translateX(-50%);background:#1f2937;color:#fff;padding:6px 10px;border-radius:8px;font-size:12px;white-space:nowrap;box-shadow:0 10px 20px rgba(15,23,42,.18);pointer-events:none;opacity:0;transition:opacity .15s ease}
      .apf-portal-calendar__day--has-event:hover .apf-portal-calendar__tooltip{opacity:1}
      .apf-portal-calendar__empty{margin:10px 0 0;font-size:13px;color:#667085}
      .apf-portal-calendar__hint{margin:10px 0 0;font-size:12px;color:#5f6b7a}
      @media(max-width:900px){
        .apf-hero{flex-direction:column;align-items:flex-start}
        .apf-hero .apf-actions{width:100%;justify-content:flex-start}
      }
      @media(max-width:720px){
        .apf-tabs{overflow:auto;padding-bottom:12px}
        .apf-tabs button{flex:0 0 auto}
        .apf-table{min-width:100%}
        .apf-table thead{display:none}
        .apf-table tbody tr{display:flex;flex-direction:column;gap:6px;padding:12px;border-bottom:1px solid #e6e9ef}
        .apf-table tbody td{border:0;padding:0}
        .apf-table tbody td::before{content:attr(data-label);display:block;font-size:12px;color:#667085;font-weight:600;margin-bottom:2px}
        .apf-table tbody td:nth-child(1)::before{content:'Data'}
        .apf-table tbody td:nth-child(2)::before{content:'Tipo'}
        .apf-table tbody td:nth-child(3)::before{content:'Nome/Empresa'}
        .apf-table tbody td:nth-child(4)::before{content:'Valor (R$)'}
        .apf-table tbody td:nth-child(5)::before{content:'Curso'}
      }
      @media(max-width:640px){
        .apf-panel .apf-actions{flex-direction:column;align-items:stretch}
        .apf-panel .apf-actions button{width:100%}
        .apf-grid{grid-template-columns:1fr}
      }
      @media(max-width:520px){
        .apf-portal-calendar__weekdays,
        .apf-portal-calendar__days{grid-template-columns:repeat(7,minmax(28px,1fr))}
      }
    </style>

    <script>
    (function(){
      const MONTH_NAMES = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
      const WEEKDAYS = ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];

      const calendarNode = document.getElementById('apfPortalCalendar');
      if (calendarNode) {
        let monthDate = new Date();
        monthDate.setDate(1);
        let events = [];
        const eventsByDate = new Map();

        try {
          const raw = JSON.parse(calendarNode.getAttribute('data-events') || '[]');
          if (Array.isArray(raw)) {
            events = raw;
          }
        } catch (_e) {}

        events.forEach(evt => {
          if (!evt || !evt.date || !evt.title) { return; }
          const iso = evt.date;
          if (!eventsByDate.has(iso)) {
            eventsByDate.set(iso, []);
          }
          eventsByDate.get(iso).push(evt);
        });

        const body = calendarNode.querySelector('.apf-portal-calendar__body');

        function renderCalendar() {
          if (!body) { return; }
          const container = document.createElement('div');
          container.className = 'apf-portal-calendar__inner';

          const header = document.createElement('div');
          header.className = 'apf-portal-calendar__header';

          const title = document.createElement('h4');
          const monthIndex = monthDate.getMonth();
          const year = monthDate.getFullYear();
          title.textContent = MONTH_NAMES[monthIndex] + ' ' + year;

          const nav = document.createElement('div');
          nav.className = 'apf-portal-calendar__nav';

          const prev = document.createElement('button');
          prev.type = 'button';
          prev.className = 'apf-portal-calendar__btn';
          prev.innerHTML = '&larr;';
          prev.addEventListener('click', () => {
            monthDate.setMonth(monthDate.getMonth() - 1, 1);
            renderCalendar();
          });

          const next = document.createElement('button');
          next.type = 'button';
          next.className = 'apf-portal-calendar__btn';
          next.innerHTML = '&rarr;';
          next.addEventListener('click', () => {
            monthDate.setMonth(monthDate.getMonth() + 1, 1);
            renderCalendar();
          });

          nav.appendChild(prev);
          nav.appendChild(next);
          header.appendChild(title);
          header.appendChild(nav);

          const weekdays = document.createElement('div');
          weekdays.className = 'apf-portal-calendar__weekdays';
          WEEKDAYS.forEach(day => {
            const span = document.createElement('span');
            span.className = 'apf-portal-calendar__weekday';
            span.textContent = day;
            weekdays.appendChild(span);
          });

          const daysGrid = document.createElement('div');
          daysGrid.className = 'apf-portal-calendar__days';
          const firstWeekday = (monthDate.getDay() + 6) % 7;
          const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
          const totalCells = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;

          for (let cell = 0; cell < totalCells; cell++) {
            const dayNumber = cell - firstWeekday + 1;
            const div = document.createElement('div');
            div.className = 'apf-portal-calendar__day';

            if (dayNumber < 1 || dayNumber > daysInMonth) {
              div.classList.add('apf-portal-calendar__day--muted');
              div.textContent = '';
            } else {
              div.textContent = String(dayNumber);
              const iso = year + '-' + String(monthIndex + 1).padStart(2, '0') + '-' + String(dayNumber).padStart(2, '0');
              if (eventsByDate.has(iso)) {
                div.classList.add('apf-portal-calendar__day--has-event');
                const tooltip = document.createElement('span');
                tooltip.className = 'apf-portal-calendar__tooltip';
                tooltip.textContent = eventsByDate.get(iso).map(evt => evt.title).join(', ');
                div.appendChild(tooltip);
              }
            }
            daysGrid.appendChild(div);
          }

          body.innerHTML = '';
          body.appendChild(header);
          body.appendChild(weekdays);
          body.appendChild(daysGrid);
        }

        renderCalendar();
      }

      const form  = document.getElementById('apfEditForm');
      const panes = Array.from(form.querySelectorAll('.apf-pane'));
      const tabs  = Array.from(form.querySelectorAll('.apf-tabs button'));
      let current = 0;

      function showStep(i){
        panes.forEach((p,idx)=>p.classList.toggle('is-active', idx===i));
        tabs.forEach((t,idx)=>t.classList.toggle('is-active', idx===i));
        current = i;
        const y = form.getBoundingClientRect().top + window.scrollY - 12;
        window.scrollTo({ top:y, behavior:'smooth' });
      }

      form.addEventListener('click', function(e){
        if(e.target.classList.contains('apf-next')){
          showStep(Math.min(current+1, panes.length-1));
        }
        if(e.target.classList.contains('apf-prev')){
          showStep(Math.max(current-1, 0));
        }
      });
      tabs.forEach((b,i)=> b.addEventListener('click', ()=>showStep(i)));
      showStep(0);

      // PF/PJ
      const radios = form.querySelectorAll('input[name="pessoa_tipo"]');
      const boxPF  = document.getElementById('apfPF');
      const boxPJ  = document.getElementById('apfPJ');
      function togglePessoa(){
        const v  = (form.querySelector('input[name="pessoa_tipo"]:checked')||{}).value || 'pj';
        const pf = (v === 'pf');
        boxPF.style.display = pf ? '' : 'none';
        boxPJ.style.display = pf ? 'none' : '';
      }
      radios.forEach(r => r.addEventListener('change', togglePessoa));
      togglePessoa();

      const coordinatorSelect = form.querySelector('select[name="nome_diretor"]');
      const courseInput = form.querySelector('input[name="nome_curto"]');
      if (coordinatorSelect) {
        const syncCourse = () => {
          if (!courseInput) return;
          const option = coordinatorSelect.options[coordinatorSelect.selectedIndex];
          if (!option) return;
          const course = option.getAttribute('data-course') || '';
          if (course) {
            courseInput.value = course;
          }
        };
        const restoreFullLabels = () => {
          Array.from(coordinatorSelect.options).forEach(opt => {
            const fullLabel = opt.getAttribute('data-full-label');
            if (fullLabel !== null) {
              opt.textContent = fullLabel;
            }
          });
        };
        const applyCoordinatorLabels = () => {
          restoreFullLabels();
          const current = coordinatorSelect.options[coordinatorSelect.selectedIndex];
          if (current) {
            const coordinatorLabel = current.getAttribute('data-coordinator-label');
            if (coordinatorLabel) {
              current.textContent = coordinatorLabel;
            }
          }
        };
        coordinatorSelect.addEventListener('change', () => {
          syncCourse();
          applyCoordinatorLabels();
        });
        coordinatorSelect.addEventListener('focus', restoreFullLabels);
        coordinatorSelect.addEventListener('blur', applyCoordinatorLabels);
        if (!courseInput || !courseInput.value) {
          syncCourse();
        }
        applyCoordinatorLabels();
      }

      // Máscaras
      const onlyNum = v => (v||'').toString().replace(/\D+/g,'');
      function maskPhone(el){
        let v = onlyNum(el.value).slice(0,11);
        if(v.length>10){ el.value = v.replace(/(\d{2})(\d{5})(\d{4})/,'($1) $2-$3'); }
        else if(v.length>6){ el.value = v.replace(/(\d{2})(\d{4})(\d{0,4})/,'($1) $2-$3'); }
        else if(v.length>2){ el.value = v.replace(/(\d{2})(\d{0,5})/,'($1) $2'); }
        else { el.value = v; }
      }
      function maskCPF(el){
        let v = onlyNum(el.value).slice(0,11);
        if(v.length>9){ el.value = v.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2})/,'$1.$2.$3-$4'); }
        else if(v.length>6){ el.value = v.replace(/(\d{3})(\d{3})(\d{0,3})/,'$1.$2.$3'); }
        else if(v.length>3){ el.value = v.replace(/(\d{3})(\d{0,3})/,'$1.$2'); }
        else { el.value = v; }
      }
      function maskCNPJ(el){
        let v = onlyNum(el.value).slice(0,14);
        if(v.length>12){ el.value = v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/,'$1.$2.$3/$4-$5'); }
        else if(v.length>8){ el.value = v.replace(/(\d{2})(\d{3})(\d{3})(\d{0,4})/,'$1.$2.$3/$4'); }
        else if(v.length>5){ el.value = v.replace(/(\d{2})(\d{3})(\d{0,3})/,'$1.$2.$3'); }
        else if(v.length>2){ el.value = v.replace(/(\d{2})(\d{0,3})/,'$1.$2'); }
        else { el.value = v; }
      }
      function maskInt(el, max){ el.value = onlyNum(el.value).slice(0,max||20); }
      function maskMoney(el){
        let v = onlyNum(el.value);
        if(!v) { el.value = ''; return; }
        while(v.length < 3) v = '0'+v;
        const cents = v.slice(-2);
        const ints  = v.slice(0,-2).replace(/\B(?=(\d{3})+(?!\d))/g,'.');
        el.value = ints + ',' + cents;
      }

      form.querySelector('input[name="tel_prestador"]').addEventListener('input', e => maskPhone(e.target));
      const cpf = form.querySelector('input[name="cpf"]');  if(cpf)  cpf.addEventListener('input', e => maskCPF(e.target));
      const cnpj= form.querySelector('input[name="cnpj"]'); if(cnpj) cnpj.addEventListener('input', e => maskCNPJ(e.target));
      form.querySelector('input[name="num_controle"]').addEventListener('input', e => maskInt(e.target, 20));
      form.querySelector('input[name="carga_horaria"]').addEventListener('input', e => maskInt(e.target, 5));
      form.querySelector('input[name="agencia"]').addEventListener('input', e => maskInt(e.target, 10));
      form.querySelector('input[name="conta"]').addEventListener('input', e => maskInt(e.target, 20));
      form.querySelector('input[name="valor"]').addEventListener('input', e => maskMoney(e.target));
    })();
    </script>
    <?php
    return ob_get_clean();
});
