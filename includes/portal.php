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
        return '<div class="apf-card" style="max-width:720px;margin:16px auto;padding:18px;border:1px solid #e6e9ef;border-radius:12px;background:#fff">Faça login para acessar o portal do prestador.</div>';
    }

    $user       = wp_get_current_user();
    $user_id    = get_current_user_id();
    $user_email = $user && ! empty($user->user_email) ? sanitize_email($user->user_email) : '';

    $apf_directors = get_option('apf_directors', array());
    if ( ! is_array($apf_directors) ) {
        $apf_directors = array();
    }
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

        $valor_bruto    = wp_unslash($_POST['valor'] ?? '');
        $valor_norm     = preg_replace('/[^\d,\.]/', '', $valor_bruto);
        $valor_norm     = str_replace(['.', ','], ['', '.'], $valor_norm);
        if ($valor_norm === '') $valor_norm = '0';

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

        // ====== UPSERT no mesmo registro do usuário ======
        // 1) tenta pelo autor
        $post_id = function_exists('apf_get_user_submission_id') ? apf_get_user_submission_id( $user_id, $email_prest ) : 0;

        // 2) fallback por e-mail, caso registros antigos sem autor
        if ( ! $post_id && $email_prest ) {
            $q = new WP_Query([
                'post_type'      => 'apf_submission',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => [[
                    'key'     => 'apf_email_prest',
                    'value'   => $email_prest,
                    'compare' => 'LIKE',
                ]],
                'fields'         => 'ids',
            ]);
            if ( $q->have_posts() && ! empty($q->posts[0]) ) {
                $post_id = (int) $q->posts[0];
                // vincula ao autor atual
                wp_update_post(['ID'=>$post_id,'post_author'=>$user_id]);
            }
        }

        // 3) cria se ainda não existir
        if ( ! $post_id ) {
            $post_id = wp_insert_post([
                'post_type'   => 'apf_submission',
                'post_title'  => 'Solicitação - ' . current_time('Y-m-d H:i'),
                'post_status' => 'publish',
                'post_author' => $user_id,
            ]);
        }

        if ( $post_id && ! is_wp_error($post_id) ) {
            // grava metas (os mesmos campos do Admin)
            foreach ($payload as $k => $v) {
                update_post_meta($post_id, 'apf_'.$k, $v);
            }

            // atualiza título (sem obrigatoriamente mexer na data)
            $titulo = 'Solicitação - ' . ( $pessoa_tipo==='pj' ? ($payload['nome_empresa'] ?: 'PJ') : ($payload['nome_prof'] ?: 'PF') ) . ' - ' . get_the_date('Y-m-d H:i', $post_id);
            wp_update_post([
                'ID'          => $post_id,
                'post_title'  => $titulo,
                'post_author' => $user_id,
                'post_status' => 'publish',
            ]);

            // PRG: evita re-envio ao dar refresh e mostra aviso
            wp_safe_redirect( add_query_arg('apf_updated','1', get_permalink()) . '#apf-portal-root' );
            exit;
        } else {
            return '<div style="max-width:720px;margin:16px auto;padding:12px;border:1px solid #fecaca;border-radius:10px;background:#fff5f5;color:#991b1b">Não foi possível salvar agora.</div>';
        }
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
              <label>Nome Completo Diretor Executivo
                <?php $current_dir = $g('nome_diretor'); ?>
                <?php if ( ! empty($apf_directors) ) : ?>
                  <select name="nome_diretor">
                    <option value="" <?php echo selected($current_dir, '', false); ?>>Selecione um diretor</option>
                    <?php foreach ( $apf_directors as $dir_entry ) :
                        $dir_name = isset($dir_entry['director']) ? trim((string) $dir_entry['director']) : '';
                        if ( $dir_name === '' ) { continue; }
                        $dir_course = isset($dir_entry['course']) ? trim((string) $dir_entry['course']) : '';
                        $label = $dir_course ? $dir_course . ' — ' . $dir_name : $dir_name;
                        $selected = selected($current_dir, $dir_name, false);
                    ?>
                      <option value="<?php echo esc_attr($dir_name); ?>" data-course="<?php echo esc_attr($dir_course); ?>" <?php echo $selected; ?>>
                        <?php echo esc_html($label); ?>
                      </option>
                    <?php endforeach; ?>
                    <?php if ( $current_dir !== '' && empty($apf_director_names[$current_dir]) ) : ?>
                      <option value="<?php echo esc_attr($current_dir); ?>" selected>
                        <?php echo esc_html($current_dir . ' (manual)'); ?>
                      </option>
                    <?php endif; ?>
                  </select>
                <?php else : ?>
                  <input type="text" name="nome_diretor" value="<?php echo esc_attr($current_dir); ?>">
                  <span class="apf-field-note">Cadastre diretores no dashboard financeiro para habilitar a seleção.</span>
                <?php endif; ?>
              </label>

              <label>Número de Controle Secretaria
                <input type="text" name="num_controle" inputmode="numeric" maxlength="20" value="<?php echo esc_attr($g('num_controle')); ?>">
              </label>

              <label>Telefone do Prestador
                <input type="text" name="tel_prestador" placeholder="(00) 00000-0000" value="<?php echo esc_attr($g('tel_prestador')); ?>">
              </label>

              <label>E-mail do Prestador
                <input type="email" name="email_prest" value="<?php echo esc_attr($g('email_prest') ?: $user_email); ?>">
              </label>

              <label>Número do Documento Fiscal
                <input type="text" name="num_doc_fiscal" value="<?php echo esc_attr($g('num_doc_fiscal')); ?>">
              </label>

              <label>Valor (R$)
                <input type="text" name="valor" placeholder="0,00" value="<?php echo esc_attr($g('valor')); ?>">
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

      .apf-hero{display:flex;gap:16px;justify-content:space-between;align-items:center;background:#fff;border:1px solid #e6e9ef;border-radius:12px;padding:18px 20px;box-shadow:0 8px 24px rgba(0,0,0,.04)}
      .apf-actions{display:flex;gap:10px;flex-wrap:wrap}
      .apf-btn{background:#eef2f7;color:#344054;text-decoration:none;border:none;border-radius:10px;padding:10px 14px;display:inline-block;cursor:pointer}
      .apf-btn.apf-primary{background:#1f6feb;color:#fff}
      .apf-panel{background:#fff;border:1px solid #e6e9ef;border-radius:12px;padding:14px 16px}
      .apf-table-wrap{overflow:auto;border:1px solid #e6e9ef;border-radius:12px}
      .apf-table{width:100%;border-collapse:collapse}
      .apf-table thead th{background:#f7f8fb;text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef;font-weight:600;color:#475467}
      .apf-table tbody td{padding:10px 12px;border-bottom:1px solid #f0f2f5}
      .apf-tabs{display:flex;gap:8px;padding:8px 0 10px}
      .apf-tabs button{border:1px solid #e6e9ef;background:#f7f8fb;border-radius:10px;padding:8px 10px;cursor:pointer}
      .apf-tabs button.is-active{background:#e8f1ff;border-color:#bcd4ff;color:#1849a9;font-weight:600}
      .apf-pane{display:none}
      .apf-pane.is-active{display:block}
      .apf-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      .apf-grid label{display:flex;flex-direction:column;font-size:13px;color:#344054}
      .apf-grid input,.apf-grid textarea,.apf-grid select{border:1px solid #d0d5dd;border-radius:10px;padding:10px 12px;font-size:14px;background:#fff;color:#344054}
      .apf-grid select{appearance:none;-webkit-appearance:none;background-image:url('data:image/svg+xml;utf8,<svg fill=\"none\" stroke=\"%23475067\" stroke-width=\"1.5\" viewBox=\"0 0 20 20\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"M6 8l4 4 4-4\" stroke-linecap=\"round\" stroke-linejoin=\"round\"/></svg>');background-position:calc(100% - 12px) 50%;background-repeat:no-repeat;padding-right:36px}
      .apf-row{display:flex;gap:16px;border:none;margin:8px 0 0;padding:0}
      .apf-radio{display:flex;align-items:center;gap:6px}
      .apf-actions{display:flex;justify-content:space-between;margin-top:16px}
      .apf-actions button{background:#1f6feb;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
      .apf-actions .apf-prev{background:#a0a7b4}
      .apf-field-note{display:block;font-size:12px;color:#b42318;margin-top:6px}
      @media(max-width:780px){ .apf-grid{grid-template-columns:1fr} }
    </style>

    <script>
    (function(){
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

      const directorSelect = form.querySelector('select[name="nome_diretor"]');
      const courseInput = form.querySelector('input[name="nome_curto"]');
      if (directorSelect && courseInput) {
        const syncCourse = () => {
          const option = directorSelect.options[directorSelect.selectedIndex];
          if (!option) return;
          const course = option.getAttribute('data-course') || '';
          if (course) {
            courseInput.value = course;
          }
        };
        directorSelect.addEventListener('change', syncCourse);
        if (!courseInput.value) {
          syncCourse();
        }
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
