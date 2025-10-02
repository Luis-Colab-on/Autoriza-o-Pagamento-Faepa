<?php
if ( ! defined('ABSPATH') ) { exit; }

/* ====== DASHBOARD: shortcode [apf_inbox] com BUSCA em JS ====== */
add_shortcode('apf_inbox', function () {

    if ( ! is_user_logged_in() || ! current_user_can('edit_posts') ) {
        return '<p>Faça login com um usuário autorizado para ver as submissões.</p>';
    }

    $q = new WP_Query(array(
        'post_type'      => 'apf_submission',
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => 500, // ajuste se quiser
    ));

    // helper
    $m = function($id,$k){ return get_post_meta($id, 'apf_'.$k, true); };

    ob_start(); ?>
    <div class="apf-inbox-wrap">
      <!-- Barra de busca (JS) -->
      <div class="apf-toolbar">
        <div class="apf-search">
          <input id="apfQuery" type="text" placeholder="Pesquisar por nome, CPF, CNPJ, telefone, e-mail, nº controle, doc fiscal..." />
          <button id="apfBtnSearch" type="button">Buscar</button>
          <button id="apfBtnClear"  type="button" class="apf-muted">Limpar</button>
        </div>
        <div class="apf-count"><span id="apfCount"></span></div>
      </div>

      <div class="apf-table-scroller">
        <table id="apfTable" class="apf-table">
          <thead>
            <tr>
              <th>Data</th>
              <th>Tipo</th>
              <th>Nome/Empresa</th>
              <th>Telefone</th>
              <th>E-mail</th>
              <th>Valor (R$)</th>
              <th>Doc. Fiscal</th>
              <th>Data Serviço</th>
              <th>Classificação</th>
              <th>Curso</th>
              <th>CH</th>
              <th>Banco/Agência/Conta</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
          <?php if ( $q->have_posts() ) :
              while ( $q->have_posts() ) : $q->the_post();
                $id   = get_the_ID();
                $tipo = $m($id,'pessoa_tipo'); // pf/pj
                $nome = ($tipo==='pj') ? $m($id,'nome_empresa') : $m($id,'nome_prof');

                $valor = $m($id,'valor');
                $valor_fmt = ($valor !== '') ? number_format((float)$valor, 2, ',', '.') : '';

                $banco = trim( ($m($id,'banco') ?: '') .' / '. ($m($id,'agencia') ?: '') .' / '. ($m($id,'conta') ?: '') );
                $edit_link = admin_url('post.php?post='.$id.'&action=edit');

                // texto concatenado (ajuda o JS se precisar usar data-search)
                $concat = trim( implode(' ', array(
                    get_the_date('Y-m-d H:i'),
                    $tipo, $nome,
                    $m($id,'tel_prestador'),
                    $m($id,'email_prest'),
                    $valor_fmt,
                    $m($id,'num_doc_fiscal'),
                    $m($id,'data_prest'),
                    $m($id,'classificacao'),
                    $m($id,'nome_curto'),
                    $m($id,'carga_horaria'),
                    $banco
                )));
          ?>
            <tr data-search="<?php echo esc_attr( $concat ); ?>">
              <td><?php echo esc_html(get_the_date('Y-m-d H:i')); ?></td>
              <td style="text-transform:uppercase;"><?php echo esc_html($tipo ?: '—'); ?></td>
              <td><?php echo esc_html($nome ?: '—'); ?></td>
              <td><?php echo esc_html($m($id,'tel_prestador') ?: '—'); ?></td>
              <td><?php echo esc_html($m($id,'email_prest') ?: '—'); ?></td>
              <td><?php echo esc_html($valor_fmt ?: '—'); ?></td>
              <td><?php echo esc_html($m($id,'num_doc_fiscal') ?: '—'); ?></td>
              <td><?php echo esc_html($m($id,'data_prest') ?: '—'); ?></td>
              <td><?php echo esc_html($m($id,'classificacao') ?: '—'); ?></td>
              <td><?php echo esc_html($m($id,'nome_curto') ?: '—'); ?></td>
              <td><?php echo esc_html($m($id,'carga_horaria') ?: '—'); ?></td>
              <td><?php echo esc_html($banco ?: '—'); ?></td>
              <td><a href="<?php echo esc_url($edit_link); ?>" target="_blank">Ver no Admin</a></td>
            </tr>
          <?php endwhile; wp_reset_postdata(); else: ?>
            <tr><td colspan="13">Nenhuma submissão encontrada.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <style>
      .apf-inbox-wrap{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial}
      .apf-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 12px}
      .apf-search{display:flex;gap:8px;flex:1}
      .apf-search input{flex:1;border:1px solid #d0d5dd;border-radius:10px;padding:10px 12px;font-size:14px;outline:none}
      .apf-search input:focus{border-color:#9ab7ff;box-shadow:0 0 0 3px rgba(63,131,248,.15)}
      .apf-search button{border:none;border-radius:10px;padding:10px 14px;font-size:14px;cursor:pointer}
      #apfBtnSearch{background:#1f6feb;color:#fff}
      #apfBtnClear{background:#eef2f7;color:#344054}
      .apf-muted{background:#a0a7b4;color:#fff}
      .apf-count{color:#667085;font-size:13px}
      .apf-table-scroller{overflow:auto;border:1px solid #e6e9ef;border-radius:12px}
      .apf-table{width:100%;border-collapse:collapse;min-width:980px}
      .apf-table thead th{background:#f7f8fb;text-align:left;padding:10px 12px;border-bottom:1px solid #e6e9ef;font-weight:600;color:#475467}
      .apf-table tbody td{padding:10px 12px;border-bottom:1px solid #f0f2f5}
      .apf-table tbody tr:nth-child(odd){background:#fcfdff}
      .apf-table tbody tr:hover{background:#f3f7ff}
      .apf-hide{display:none !important}
      .apf-highlight{background:linear-gradient(transparent 60%, #fff1a8 0)}
    </style>

    <script>
    (function(){
      // util: remover acentos e normalizar
      function norm(s){
        return (s || '')
          .toString()
          .normalize('NFD').replace(/[\\u0300-\\u036f]/g,'') // sem acentos
          .toLowerCase().trim();
      }
      function digits(s){ return (s||'').toString().replace(/\\D+/g,''); }

      const $ = (sel, ctx)=> (ctx||document).querySelector(sel);
      const $$ = (sel, ctx)=> Array.prototype.slice.call((ctx||document).querySelectorAll(sel));

      const input = $('#apfQuery');
      const btnSearch = $('#apfBtnSearch');
      const btnClear  = $('#apfBtnClear');
      const rows = $$('#apfTable tbody tr');
      const countEl = $('#apfCount');

      function highlightRow(row, q){
        // remove destaques anteriores
        $$('.apf-highlight', row).forEach(el=>{
          el.outerHTML = el.textContent;
        });

        if(!q){ return; }
        const qn = norm(q);
        const qd = digits(q);

        // destaca ocorrências simples em células de texto
        $$('#apfTable tbody td', row).forEach(td=>{
          // não mexe na célula "Ações"
          if(td.textContent.indexOf('Ver no Admin') !== -1) return;

          const txt = td.textContent;
          const base = txt; // preserva
          let html = base;

          // highlight por texto normalizado
          if(qn && norm(base).indexOf(qn) !== -1){
            try{
              const esc = q.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&');
              const re = new RegExp(esc, 'ig');
              html = base.replace(re, m=>'<span class="apf-highlight">'+m+'</span>');
            }catch(e){}
          }
          td.innerHTML = html;
        });
      }

      function applyFilter(){
        const q = input.value || '';
        const qn = norm(q);
        const qd = digits(q);

        let visible = 0;
        rows.forEach(row=>{
          const raw = row.getAttribute('data-search') || row.innerText || '';
          const n = norm(raw);
          const d = digits(raw);

          const okText = qn ? (n.indexOf(qn) !== -1) : true;
          const okDig  = qd ? (d.indexOf(qd)  !== -1) : true;

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

      // debounce leve
      let t = null;
      input.addEventListener('input', function(){
        clearTimeout(t);
        t = setTimeout(applyFilter, 180);
      });
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
