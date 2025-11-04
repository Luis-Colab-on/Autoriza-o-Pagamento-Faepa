<?php
if ( ! defined('ABSPATH') ) { exit; }

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

    $q = new WP_Query(array(
        'post_type'      => 'apf_submission',
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => 500,
    ));

    $m = function($id,$k){ return get_post_meta($id, 'apf_'.$k, true); };

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
              <input type="text" name="apf_dir_course" required placeholder="Ex.: Curso de Atualização em XYZ">
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
                    <input type="text" name="apf_dir_course" value="<?php echo esc_attr($entry['course'] ?? ''); ?>" required>
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

      <!-- Toolbar / Busca -->
      <div class="apf-toolbar">
        <form class="apf-search" role="search" aria-label="Busca no dashboard" onsubmit="return false;">
          <input id="apfQuery" type="search" inputmode="search" autocomplete="off"
                 placeholder="Pesquisar por nome, CPF, CNPJ, telefone, e-mail, nº controle, doc fiscal..." aria-label="Pesquisar" />
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
          <?php if ( $q->have_posts() ) :
              while ( $q->have_posts() ) : $q->the_post();
                $id   = get_the_ID();

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

                $banco  = trim( ($m($id,'banco') ?: '') .' / '. ($m($id,'agencia') ?: '') .' / '. ($m($id,'conta') ?: '') );
                $admin_url = admin_url('post.php?post='.$id.'&action=edit');

                // Detalhes completos para o modal
                $payload = array(
                  'Data'                   => get_the_date('Y-m-d H:i'),
                  'Tipo'                   => strtoupper($tipo ?: '—'),
                  'Nome/Empresa'           => $nome ?: '—',
                  'Telefone'               => $tel ?: '—',
                  'E-mail'                 => $mail ?: '—',
                  'Valor (R$)'             => $valor_fmt ?: '—',
                  'Doc. Fiscal'            => $docf ?: '—',
                  'Data do Serviço'        => $dserv ?: '—',
                  'Classificação'          => $class ?: '—',
                  'Curso'                  => $curso ?: '—',
                  'Carga Horária (CH)'     => $ch ?: '—',
                  'Banco/Agência/Conta'    => $banco ?: '—',
                  '_admin_url'             => $admin_url, // link para o modal
                );
                $data_json = esc_attr( wp_json_encode($payload, JSON_UNESCAPED_UNICODE) );

                // concat p/ busca (mantemos tudo para achar mesmo fora das colunas visíveis)
                $concat = trim( implode(' ', array_values($payload) ) );
          ?>
            <tr data-search="<?php echo esc_attr( $concat ); ?>" data-json="<?php echo $data_json; ?>">
              <td class="apf-uppercase"><?php echo esc_html($payload['Tipo']); ?></td>
              <td class="apf-break"><?php echo esc_html($payload['Nome/Empresa']); ?></td>
              <td class="apf-break"><?php echo esc_html($payload['Telefone']); ?></td>
              <td class="apf-break"><?php echo esc_html($payload['E-mail']); ?></td>
              <td class="apf-col--num apf-nowrap" title="<?php echo esc_attr($payload['Valor (R$)']); ?>"><?php echo esc_html($payload['Valor (R$)']); ?></td>
              <td class="apf-break"><?php echo esc_html($payload['Curso']); ?></td>
              <td class="apf-actions">
                <button type="button" class="apf-link apf-btn--inline apf-btn-details">Detalhes</button>
              </td>
            </tr>
          <?php endwhile; wp_reset_postdata(); else: ?>
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
            <button class="apf-modal__close" type="button" aria-label="Fechar" data-close>&times;</button>
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
      .apf-toolbar{ display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin:8px 0 14px; }
      .apf-search{ display:flex; gap:10px; flex:1; align-items:center; }
      .apf-search input{
        flex:1; height:42px; border:1px solid var(--apf-border); border-radius:999px;
        padding:0 14px; font-size:14px; background:var(--apf-bg); color:var(--apf-text);
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
      .apf-directors__grid input{
        border:1px solid var(--apf-border);
        border-radius:10px;
        padding:10px 12px;
        font-size:14px;
        background:var(--apf-bg);
        color:var(--apf-text);
      }
      .apf-directors__grid input:focus{
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
      .apf-modal__header{ display:flex; align-items:center; justify-content:space-between; }
      .apf-details{ display:grid; grid-template-columns: 220px 1fr; gap:10px 16px; }
      .apf-details dt{ color:var(--apf-muted); }
      .apf-details dd{ margin:0; }

      /* Mobile cards (agora com 7 colunas) */
      @media (max-width: 920px){
        .apf-table{ min-width:100%; }
        .apf-table thead{ display:none; }
        .apf-table tbody tr{ display:grid; grid-template-columns:1fr 1fr; gap:6px 12px; padding:12px; border-bottom:1px solid var(--apf-border); }
        .apf-table tbody td{ display:block; padding:0; border:0; }
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
      const rows = $$('#apfTable tbody tr');
      const countEl = $('#apfCount');

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

      function applyFilter(){
        const q = input.value || '';
        const qn = norm(q), qd = digits(q);
        let visible = 0;
        rows.forEach(row=>{
          const raw = row.getAttribute('data-search') || row.innerText || '';
          const n = norm(raw), d = digits(raw);
          const okText = qn ? n.includes(qn) : true;
          const okDig  = qd ? d.includes(qd) : true;
          const show = okText && okDig;
          row.classList.toggle('apf-hide', !show);
          if(show){ visible++; highlightRow(row, q); }
        });
        countEl.textContent = q ? (visible + ' resultado(s)') : (rows.length + ' registro(s)');
      }

      function clearFilter(){
        input.value = '';
        rows.forEach(r=>{
          r.classList.remove('apf-hide');
          $$('.apf-highlight', r).forEach(el=>{ el.outerHTML = el.textContent; });
        });
        countEl.textContent = rows.length + ' registro(s)';
        input.focus();
      }

      // Modal
      const modal = $('#apfModal');
      const details = $('#apfDetails');
      const adminLink = $('#apfAdminLink');
      let lastFocus = null;

      function openModal(data){
        // monta DL com todas as chaves (exceto _*)
        details.innerHTML = '';
        Object.keys(data).forEach(k=>{
          if(k[0] === '_') return; // não mostra meta
          const dt = document.createElement('dt'); dt.textContent = k;
          const dd = document.createElement('dd'); dd.textContent = (data[k] ?? '—');
          details.appendChild(dt); details.appendChild(dd);
        });
        // link admin
        if(data._admin_url){
          adminLink.href = data._admin_url;
          adminLink.style.display = '';
        }else{
          adminLink.style.display = 'none';
        }

        modal.setAttribute('aria-hidden','false');
        lastFocus = document.activeElement;
        $('.apf-modal__close', modal).focus();
        document.body.style.overflow = 'hidden';
      }
      function closeModal(){
        modal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
        if(lastFocus){ lastFocus.focus(); }
      }

      document.addEventListener('click', (e)=>{
        const btn = e.target.closest('.apf-btn-details');
        if(btn){
          const tr = btn.closest('tr');
          try{
            const data = JSON.parse(tr.getAttribute('data-json') || '{}');
            openModal(data);
          }catch(_e){}
        }
        if(e.target.matches('[data-close]')) closeModal();
      });
      document.addEventListener('keydown', (e)=>{
        if(e.key === 'Escape' && modal.getAttribute('aria-hidden') === 'false'){ closeModal(); }
      });

      // Busca
      let t = null;
      input.addEventListener('input', function(){ clearTimeout(t); t = setTimeout(applyFilter, 180); });
      btnSearch.addEventListener('click', applyFilter);
      btnClear.addEventListener('click', clearFilter);
      input.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); applyFilter(); } });

      // contador inicial
      countEl.textContent = rows.length + ' registro(s)';
    })();
    </script>
    <?php
    return ob_get_clean();
});
