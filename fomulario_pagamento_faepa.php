<?php
/**
 * Plugin Name: Autorização Pagamento FAEPA – Form 3 Abas
 * Description: Formulário em 3 passos (shortcode [apf_form]) + portal do prestador + dashboard [apf_inbox].
 * Version: 0.4.0
 * Author: Você
 * License: GPLv2 or later
 */

if ( ! defined('ABSPATH') ) { exit; }

if ( ! function_exists( 'apf_normalize_currency_value' ) ) {
    /**
     * Converte valores monetários informados com ponto ou vírgula para o formato "1234.56".
     * Remove separadores de milhar e garante duas casas decimais quando necessário.
     */
    function apf_normalize_currency_value( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '0';
        }

        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '0';
        }

        $value = preg_replace( '/[^\d,\.]/', '', $value );
        if ( '' === $value ) {
            return '0';
        }

        $has_comma = strpos( $value, ',' ) !== false;
        $has_dot   = strpos( $value, '.' ) !== false;

        if ( $has_comma && $has_dot ) {
            $value = str_replace( '.', '', $value );
            $value = str_replace( ',', '.', $value );
        } elseif ( $has_comma ) {
            $value = str_replace( ',', '.', $value );
        } elseif ( $has_dot && substr_count( $value, '.' ) > 1 ) {
            // múltiplos pontos sem vírgula => tratamos como separador de milhar
            $value = str_replace( '.', '', $value );
            $has_dot = false;
        }

        if ( '' === $value ) {
            return '0';
        }

        if ( $value[0] === '.' ) {
            $value = '0' . $value;
        }

        $has_decimal = strpos( $value, '.' ) !== false;
        $float_value = (float) $value;

        if ( 0.0 === $float_value ) {
            return '0';
        }

        if ( $has_decimal ) {
            return number_format( $float_value, 2, '.', '' );
        }

        return (string) (int) round( $float_value );
    }
}

if ( ! function_exists( 'apf_format_currency_for_input' ) ) {
    /**
     * Formata o valor normalizado (1234.56) para exibição em inputs (1.234,56).
     */
    function apf_format_currency_for_input( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $value = trim( (string) $value );
        if ( '' === $value || '0' === $value ) {
            return '';
        }
        $float_value = (float) str_replace( ',', '.', $value );
        if ( 0.0 === $float_value ) {
            return '';
        }
        return number_format( $float_value, 2, ',', '.' );
    }
}

if ( ! function_exists( 'apf_normalize_directors_list' ) ) {
    /**
     * Garante que cada coordenador possua um status conhecido.
     *
     * @param array $directors
     * @return array
     */
    function apf_normalize_directors_list( $directors ) {
        if ( ! is_array( $directors ) ) {
            return array();
        }

        foreach ( $directors as $idx => $entry ) {
            if ( ! is_array( $entry ) ) {
                unset( $directors[ $idx ] );
                continue;
            }
            $status = isset( $entry['status'] ) ? strtolower( trim( (string) $entry['status'] ) ) : '';
            if ( ! in_array( $status, array( 'approved', 'pending', 'rejected' ), true ) ) {
                $status = 'approved';
            }
            $directors[ $idx ]['status'] = $status;
        }

        return array_values( $directors );
    }
}

if ( ! function_exists( 'apf_filter_approved_directors' ) ) {
    /**
     * Retorna apenas coordenadores aprovados.
     *
     * @param array $directors
     * @return array
     */
    function apf_filter_approved_directors( $directors ) {
        $directors = apf_normalize_directors_list( $directors );
        return array_values( array_filter( $directors, function( $entry ) {
            return isset( $entry['status'] ) && 'approved' === $entry['status'];
        } ) );
    }
}

if ( ! function_exists( 'apf_get_user_channel_email' ) ) {
    /**
     * Obtém o e-mail preferencial do usuário para um canal específico.
     *
     * @param int    $user_id
     * @param string $channel collab|coordinator
     * @return string
     */
    function apf_get_user_channel_email( $user_id, $channel = 'collab' ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return '';
        }
        $channel = ( 'coordinator' === $channel ) ? 'coordinator' : 'collab';
        $meta_key = 'apf_channel_email_' . $channel;
        $value = get_user_meta( $user_id, $meta_key, true );
        $value = is_scalar( $value ) ? sanitize_email( $value ) : '';
        return $value;
    }
}

if ( ! function_exists( 'apf_set_user_channel_email' ) ) {
    /**
     * Atualiza ou remove o e-mail preferencial do usuário para um canal.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $email
     */
    function apf_set_user_channel_email( $user_id, $channel, $email ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }
        $channel = ( 'coordinator' === $channel ) ? 'coordinator' : 'collab';
        $meta_key = 'apf_channel_email_' . $channel;
        $email    = sanitize_email( $email );
        if ( '' === $email ) {
            delete_user_meta( $user_id, $meta_key );
        } else {
            update_user_meta( $user_id, $meta_key, $email );
        }
    }
}

if ( ! function_exists( 'apf_resolve_channel_email' ) ) {
    /**
     * Resolve o e-mail final a ser usado em um canal, aplicando fallback.
     */
    function apf_resolve_channel_email( $user_id, $channel, $fallback = '' ) {
        $alias = apf_get_user_channel_email( $user_id, $channel );
        if ( '' !== $alias ) {
            return $alias;
        }
        return sanitize_email( $fallback );
    }
}

if ( ! function_exists( 'apf_get_coordinator_requests' ) ) {
    /**
     * Recupera as solicitações enviadas aos coordenadores.
     *
     * @return array<int,array<string,mixed>>
     */
    function apf_get_coordinator_requests() {
        $requests = get_option( 'apf_coordinator_requests', array() );
        return is_array( $requests ) ? $requests : array();
    }
}

if ( ! function_exists( 'apf_store_coordinator_requests' ) ) {
    /**
     * Salva a lista completa de solicitações.
     *
     * @param array $requests
     */
    function apf_store_coordinator_requests( $requests ) {
        if ( ! is_array( $requests ) ) {
            $requests = array();
        }
        update_option( 'apf_coordinator_requests', array_values( $requests ), false );
    }
}

if ( ! function_exists( 'apf_add_coordinator_requests' ) ) {
    /**
     * Anexa novas solicitações ao registro global.
     *
     * @param array<int,array<string,mixed>> $records
     */
    function apf_add_coordinator_requests( $records ) {
        if ( empty( $records ) || ! is_array( $records ) ) {
            return;
        }
        $current = apf_get_coordinator_requests();
        foreach ( $records as $record ) {
            if ( ! is_array( $record ) || empty( $record['id'] ) ) {
                continue;
            }
            $current[] = $record;
        }
        apf_store_coordinator_requests( $current );
    }
}

if ( ! function_exists( 'apf_set_coordinator_request_status' ) ) {
    /**
     * Atualiza o status de uma solicitação específica.
     *
     * @param string $request_id
     * @param string $status     pending|approved|rejected
     * @param array  $extra      Dados adicionais (user_id, note, timestamp)
     * @return bool
     */
    function apf_set_coordinator_request_status( $request_id, $status, $extra = array() ) {
        $request_id = sanitize_text_field( $request_id );
        if ( '' === $request_id ) {
            return false;
        }
        $allowed_status = array( 'pending', 'approved', 'rejected' );
        if ( ! in_array( $status, $allowed_status, true ) ) {
            $status = 'pending';
        }

        $requests = apf_get_coordinator_requests();
        $updated  = false;
        foreach ( $requests as $index => $request ) {
            if ( ! is_array( $request ) || ! isset( $request['id'] ) ) {
                continue;
            }
            if ( $request['id'] !== $request_id ) {
                continue;
            }
            if ( ! empty( $request['batch_submitted'] ) ) {
                // Lote já enviado ao financeiro; não permite alterações unitárias.
                return false;
            }
            $requests[ $index ]['status']      = $status;
            $requests[ $index ]['updated_at']  = time();
            $requests[ $index ]['decision_at'] = time();
            $requests[ $index ]['decision_by'] = isset( $extra['user_id'] ) ? (int) $extra['user_id'] : get_current_user_id();
            if ( isset( $extra['note'] ) ) {
                $requests[ $index ]['decision_note'] = sanitize_text_field( $extra['note'] );
            }
            $updated = true;
            break;
        }

        if ( $updated ) {
            apf_store_coordinator_requests( $requests );
        }

        return $updated;
    }
}

if ( ! function_exists( 'apf_mark_coordinator_batch_submitted' ) ) {
    /**
     * Marca todas as solicitações de um lote como enviadas ao financeiro.
     *
     * @param string $batch_id
     * @param array  $extra { user_id?:int }
     * @return bool
     */
function apf_mark_coordinator_batch_submitted( $batch_id, $extra = array() ) {
    $batch_id = sanitize_text_field( (string) $batch_id );
    if ( '' === $batch_id ) {
        return false;
    }
        $requests = apf_get_coordinator_requests();
        $updated  = false;
        $timestamp = time();
        $user_id = isset( $extra['user_id'] ) ? (int) $extra['user_id'] : get_current_user_id();

        foreach ( $requests as $index => $request ) {
            if ( ! is_array( $request ) || ! isset( $request['batch_id'] ) ) {
                continue;
            }
            if ( $request['batch_id'] !== $batch_id ) {
                continue;
            }
            if ( isset( $request['status'] ) && 'approved' !== $request['status'] ) {
                // Apenas aprovados seguem para o financeiro/FAEPA.
                continue;
            }
            if ( ! empty( $request['batch_submitted'] ) ) {
                continue;
            }
            $requests[ $index ]['batch_submitted']    = true;
            $requests[ $index ]['batch_submitted_at'] = $timestamp;
            $requests[ $index ]['batch_submitted_by'] = $user_id;
            $updated = true;
        }

        if ( $updated ) {
            apf_store_coordinator_requests( $requests );
        }

    return $updated;
}
}

if ( ! function_exists( 'apf_mark_coordinator_batch_forwarded' ) ) {
    /**
     * Marca um lote como enviado à FAEPA (após validação do financeiro).
     *
     * @param string $batch_id
     * @param array  $args { note?:string, user_id?:int, timestamp?:int }
     * @return bool
     */
    function apf_mark_coordinator_batch_forwarded( $batch_id, $args = array() ) {
        $batch_id = sanitize_text_field( (string) $batch_id );
        if ( '' === $batch_id ) {
            return false;
        }

        $requests  = apf_get_coordinator_requests();
        $timestamp = isset( $args['timestamp'] ) ? (int) $args['timestamp'] : time();
        $user_id   = isset( $args['user_id'] ) ? (int) $args['user_id'] : get_current_user_id();
        $note      = isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '';

        $has_approved = false;
        $updated = false;
        foreach ( $requests as $index => $request ) {
            if ( ! is_array( $request ) || ! isset( $request['batch_id'] ) ) {
                continue;
            }
            if ( $request['batch_id'] !== $batch_id ) {
                continue;
            }
            $current_status = isset( $request['status'] ) ? sanitize_key( $request['status'] ) : 'pending';
            if ( 'approved' !== $current_status ) {
                continue;
            }

            $has_approved = true;
            $requests[ $index ]['faepa_forwarded']     = true;
            $requests[ $index ]['faepa_forwarded_at']  = $timestamp;
            $requests[ $index ]['faepa_forwarded_by']  = $user_id;
            if ( '' !== $note ) {
                $requests[ $index ]['faepa_forwarded_note'] = $note;
            }
            $updated = true;
        }

        if ( ! $has_approved ) {
            return false;
        }
        if ( $updated ) {
            apf_store_coordinator_requests( $requests );
        }

        return $updated;
    }
}

if ( ! function_exists( 'apf_coord_build_request_details' ) ) {
    /**
     * Monta os detalhes exibidos no painel do coordenador/financeiro.
     *
     * @param array $entry
     * @return array<string,mixed>
     */
    function apf_coord_build_request_details( $entry ) {
        $payload = ( isset( $entry['payload'] ) && is_array( $entry['payload'] ) ) ? $entry['payload'] : array();
        $submission_id = isset( $entry['submission_id'] ) ? (int) $entry['submission_id'] : 0;
        $payment_snapshot = array();
        $service_snapshot = array();
        $payout_snapshot  = array();
        if ( isset( $entry['snapshot_payment'] ) && is_array( $entry['snapshot_payment'] ) ) {
            foreach ( $entry['snapshot_payment'] as $label => $value ) {
                $payment_snapshot[ sanitize_text_field( (string) $label ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
            }
        }
        if ( isset( $entry['snapshot_service'] ) && is_array( $entry['snapshot_service'] ) ) {
            foreach ( $entry['snapshot_service'] as $label => $value ) {
                $service_snapshot[ sanitize_text_field( (string) $label ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
            }
        }
        if ( isset( $entry['snapshot_payout'] ) && is_array( $entry['snapshot_payout'] ) ) {
            foreach ( $entry['snapshot_payout'] as $label => $value ) {
                $payout_snapshot[ sanitize_text_field( (string) $label ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
            }
        }

        $admin_url = '';
        if ( ! empty( $entry['submission_admin_url'] ) ) {
            $admin_url = esc_url_raw( $entry['submission_admin_url'] );
        } elseif ( $submission_id > 0 ) {
            $admin_url = get_edit_post_link( $submission_id, '' );
        } elseif ( isset( $payload['_admin_url'] ) ) {
            $admin_url = esc_url_raw( $payload['_admin_url'] );
        }

        $meta_lookup = function( $key ) use ( $submission_id ) {
            if ( $submission_id <= 0 ) {
                return '';
            }
            return get_post_meta( $submission_id, 'apf_' . $key, true );
        };

        $clean_payload = array();
        if ( ! empty( $payload ) ) {
            foreach ( $payload as $payload_key => $payload_value ) {
                if ( '_admin_url' === $payload_key ) {
                    $clean_payload['_admin_url'] = esc_url_raw( $payload_value );
                    continue;
                }
                $clean_payload[ $payload_key ] = is_scalar( $payload_value ) ? sanitize_text_field( (string) $payload_value ) : '';
            }
        }

        $director_name = $meta_lookup( 'nome_diretor' );
        if ( '' === $director_name && isset( $clean_payload['Coordenador'] ) ) {
            $director_name = $clean_payload['Coordenador'];
        }
        $director_name = sanitize_text_field( $director_name );

        $control_number = sanitize_text_field( $meta_lookup( 'num_controle' ) );
        $phone_value    = sanitize_text_field( $meta_lookup( 'tel_prestador' ) ?: ( $clean_payload['Telefone'] ?? '' ) );
        $email_value    = sanitize_email( $meta_lookup( 'email_prest' ) ?: ( $clean_payload['E-mail'] ?? '' ) );
        $doc_fiscal     = sanitize_text_field( $meta_lookup( 'num_doc_fiscal' ) ?: ( $clean_payload['Doc. Fiscal'] ?? '' ) );
        $raw_value      = $meta_lookup( 'valor' );
        $value_display  = '';
        if ( '' !== $raw_value ) {
            $value_display = apf_format_currency_for_input( apf_normalize_currency_value( $raw_value ) );
        }
        $person_type  = sanitize_text_field( $meta_lookup( 'pessoa_tipo' ) );
        $is_pj        = ( 'pj' === strtolower( $person_type ) );
        $person_label = $is_pj ? 'Pessoa Jurídica' : 'Pessoa Física';
        $company_name = sanitize_text_field( $meta_lookup( 'nome_empresa' ) ?: ( $clean_payload['Nome da Empresa'] ?? ( $clean_payload['Empresa (PJ)'] ?? '' ) ) );
        $collab_name  = $is_pj
            ? sanitize_text_field( $meta_lookup( 'nome_colaborador' ) ?: ( $clean_payload['Nome do colaborador'] ?? $clean_payload['Nome do Prestador'] ?? $clean_payload['Nome/Empresa'] ?? '' ) )
            : sanitize_text_field( $meta_lookup( 'nome_prof' ) ?: ( $clean_payload['Nome do Prestador'] ?? $clean_payload['Nome/Empresa'] ?? '' ) );
        $person_name  = $is_pj ? ( $collab_name ?: $company_name ) : ( $collab_name ?: '' );
        $person_doc   = $is_pj
            ? sanitize_text_field( $meta_lookup( 'cnpj' ) ?: ( $clean_payload['CNPJ'] ?? '' ) )
            : sanitize_text_field( $meta_lookup( 'cpf' ) ?: ( $clean_payload['CPF'] ?? '' ) );

        $prest_contas = sanitize_text_field( $meta_lookup( 'prest_contas' ) );
        $data_prest   = sanitize_text_field( $meta_lookup( 'data_prest' ) ?: ( $clean_payload['Data do Serviço'] ?? '' ) );
        $class_text   = sanitize_text_field( $meta_lookup( 'classificacao' ) ?: ( $clean_payload['Classificação'] ?? '' ) );
        $descricao    = sanitize_textarea_field( $meta_lookup( 'descricao' ) );
        $carga        = sanitize_text_field( $meta_lookup( 'carga_horaria' ) ?: ( $clean_payload['Carga Horária (CH)'] ?? '' ) );
        $course       = sanitize_text_field( $meta_lookup( 'nome_curto' ) ?: ( $clean_payload['Curso'] ?? '' ) );
        $bank_name    = sanitize_text_field( $meta_lookup( 'banco' ) );
        $bank_agency  = sanitize_text_field( $meta_lookup( 'agencia' ) );
        $bank_account = sanitize_text_field( $meta_lookup( 'conta' ) );

        if ( '' === $bank_name && '' === $bank_agency && '' === $bank_account && isset( $clean_payload['Banco/Agência/Conta'] ) ) {
            $parts = array_map( 'trim', explode( '/', (string) $clean_payload['Banco/Agência/Conta'] ) );
            if ( isset( $parts[0] ) && '' === $bank_name ) {
                $bank_name = sanitize_text_field( $parts[0] );
            }
            if ( isset( $parts[1] ) && '' === $bank_agency ) {
                $bank_agency = sanitize_text_field( $parts[1] );
            }
            if ( isset( $parts[2] ) && '' === $bank_account ) {
                $bank_account = sanitize_text_field( $parts[2] );
            }
        }

        $payment_fallback = array(
            'Tipo do prestador'               => $person_label,
            'Empresa (PJ)'                    => $is_pj ? ( $company_name ?: '—' ) : '',
            'Nome do colaborador'             => $person_name ?: '—',
            'Documento (CPF/CNPJ)'            => $person_doc ?: '—',
            'Nome Completo Diretor Executivo' => $director_name ?: '—',
            'Número de Controle Secretaria'   => $control_number ?: '—',
            'Telefone do Prestador'           => $phone_value ?: '—',
            'E-mail do Prestador'             => $email_value ?: '—',
            'Curso'                           => $course ?: '—',
            'Número do Documento Fiscal'      => $doc_fiscal ?: '—',
            'Valor (R$)'                      => $value_display ?: ( $entry['provider_value'] ?? '—' ),
        );
        $service_fallback = array(
            'Prestação de contas'             => $prest_contas ?: '—',
            'Data de prestação de serviço'    => $data_prest ?: '—',
            'Classificação'                   => $class_text ?: '—',
            'Descrição do serviço ou material'=> $descricao ?: ( $clean_payload['Descrição'] ?? '' ),
            'Carga horária do curso'          => $carga ?: '—',
        );
        $payout_fallback = array(
            'Banco'          => $bank_name ?: '—',
            'Agência'        => $bank_agency ?: '—',
            'Conta Corrente' => $bank_account ?: '—',
        );

        $payment_data = ! empty( $payment_snapshot ) ? $payment_snapshot : $payment_fallback;
        $service_data = ! empty( $service_snapshot ) ? $service_snapshot : $service_fallback;
        $payout_data  = ! empty( $payout_snapshot )  ? $payout_snapshot  : array();

        foreach ( $payment_fallback as $label => $value ) {
            if ( ! isset( $payment_data[ $label ] ) || '' === $payment_data[ $label ] ) {
                $payment_data[ $label ] = $value;
            }
        }
        foreach ( $service_fallback as $label => $value ) {
            if ( ! isset( $service_data[ $label ] ) || '' === $service_data[ $label ] ) {
                $service_data[ $label ] = $value;
            }
        }
        foreach ( $payout_fallback as $label => $value ) {
            if ( ! isset( $payout_data[ $label ] ) || '' === $payout_data[ $label ] ) {
                $payout_data[ $label ] = $value;
            }
        }
        $provider_type_label = $person_label ?: ( $payment_data['Tipo do prestador'] ?? '' );
        $provider_name_label = $person_name ?: ( $payment_data['Nome do colaborador'] ?? $payment_data['Nome do Prestador'] ?? '' );
        $provider_document   = $person_doc ?: ( $payment_data['Documento (CPF/CNPJ)'] ?? '' );
        $provider_company    = $is_pj
            ? ( $company_name ?: ( $payment_data['Empresa (PJ)'] ?? ( $payment_data['Nome da Empresa'] ?? '' ) ) )
            : '';
        $director_display    = $payment_data['Nome Completo Diretor Executivo'] ?? ( $director_name ?: '—' );
        $control_display     = $payment_data['Número de Controle Secretaria'] ?? ( $control_number ?: '—' );
        $ordered_payment = array(
            'Nome Completo Diretor Executivo' => $director_display ?: '—',
            'Número de Controle Secretaria'   => $control_display ?: '—',
            'Tipo do prestador'               => $provider_type_label ?: '—',
        );
        if ( $is_pj ) {
            $ordered_payment['Empresa (PJ)'] = $provider_company ?: '—';
        }
        $ordered_payment['Nome do colaborador'] = $provider_name_label ?: '—';
        $ordered_payment['Documento (CPF/CNPJ)'] = $provider_document ?: '—';

        foreach ( $payment_data as $label => $value ) {
            if ( isset( $ordered_payment[ $label ] ) ) {
                if ( '' === $ordered_payment[ $label ] || '—' === $ordered_payment[ $label ] ) {
                    $ordered_payment[ $label ] = $value ?: '—';
                }
                continue;
            }
            if ( ! $is_pj && 'Empresa (PJ)' === $label ) {
                continue;
            }
            if ( 'Nome do Prestador' === $label || 'Nome da Empresa' === $label ) {
                continue;
            }
            $ordered_payment[ $label ] = $value;
        }
        $payment_data = $ordered_payment;
        if ( empty( $payout_data ) ) {
            $payout_data = $payout_fallback;
        }

        if ( ! empty( $payout_data ) ) {
            foreach ( array( 'Banco', 'Agência', 'Conta Corrente', 'Conta' ) as $bank_label ) {
                if ( isset( $payment_data[ $bank_label ] ) ) {
                    unset( $payment_data[ $bank_label ] );
                }
            }
        }

        return array(
            'payment'   => $payment_data,
            'service'   => $service_data,
            'payout'    => $payout_data,
            'admin_url' => $admin_url,
        );
    }
}

if ( ! function_exists( 'apf_coord_render_request_detail_inner' ) ) {
    /**
     * Renderiza a listagem de colaboradores de uma solicitação.
     *
     * @param array $group
     * @param array $args { readonly?:bool, lock_actions?:bool }
     * @return string
     */
    function apf_coord_render_request_detail_inner( $group, $args = array() ) {
        if ( empty( $group ) || ! is_array( $group ) ) {
            return '';
        }

        $options = array(
            'readonly'    => ! empty( $args['readonly'] ),
            'lock_actions'=> ! empty( $args['lock_actions'] ),
        );
        if ( $options['readonly'] ) {
            $options['lock_actions'] = true;
        }

        $group_id      = isset( $group['id'] ) ? sanitize_text_field( (string) $group['id'] ) : uniqid( 'req_' );
        $group_title   = isset( $group['title'] ) && $group['title'] ? sanitize_text_field( $group['title'] ) : 'Solicitação do financeiro';
        $group_sent    = isset( $group['created_at'] ) && $group['created_at']
            ? date_i18n( 'd/m/Y H:i', (int) $group['created_at'] )
            : '';
        $group_message = isset( $group['message'] ) && $group['message']
            ? nl2br( esc_html( $group['message'] ) )
            : 'Sem observações adicionais.';

        ob_start();
        ?>
        <div class="apf-coord-request-detail__head">
          <div>
            <h4><?php echo esc_html( $group_title ); ?></h4>
            <?php if ( $group_sent ) : ?>
              <small>Enviado em <?php echo esc_html( $group_sent ); ?></small>
            <?php endif; ?>
          </div>
        </div>
        <div class="apf-coord-request-detail__message">
          <?php echo $group_message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <div class="apf-coord-request-detail__list">
          <?php if ( ! empty( $group['items'] ) ) :
              foreach ( $group['items'] as $index => $item ) :
                  $entry   = $item['entry'] ?? array();
                  $details = $item['details'] ?? array();
                  $status  = isset( $entry['status'] ) ? $entry['status'] : 'pending';
                  $status_label = 'pending' === $status ? 'Pendente' : ( 'approved' === $status ? 'Aprovado' : 'Recusado' );
                  $value_label  = isset( $entry['provider_value'] ) ? $entry['provider_value'] : '';
                  $sent_at      = isset( $entry['created_at'] ) && $entry['created_at']
                      ? date_i18n( 'd/m/Y H:i', (int) $entry['created_at'] )
                      : '';
                  $decision_at  = isset( $entry['decision_at'] ) && $entry['decision_at']
                      ? date_i18n( 'd/m/Y H:i', (int) $entry['decision_at'] )
                      : '';
                  $panel_id = 'apfCoordCollab-' . sanitize_html_class( $group_id . '-' . ( $entry['id'] ?? $index ) );
                  $payment_details = isset( $details['payment'] ) && is_array( $details['payment'] ) ? $details['payment'] : array();
                  $service_details = isset( $details['service'] ) && is_array( $details['service'] ) ? $details['service'] : array();
                  $payout_details  = isset( $details['payout'] ) && is_array( $details['payout'] ) ? $details['payout'] : array();
                  $company_label   = '';
                  if ( isset( $payment_details['Empresa (PJ)'] ) ) {
                      $company_label = sanitize_text_field( (string) $payment_details['Empresa (PJ)'] );
                  } elseif ( isset( $payment_details['Nome da Empresa'] ) ) {
                      $company_label = sanitize_text_field( (string) $payment_details['Nome da Empresa'] );
                  }
                  if ( '—' === $company_label ) {
                      $company_label = '';
                  }
          ?>
            <article class="apf-coord-collab apf-coord-collab--<?php echo esc_attr( $status ); ?>">
              <div class="apf-coord-collab__header">
                <button type="button" class="apf-coord-collab__toggle" data-collab-toggle="<?php echo esc_attr( $panel_id ); ?>" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>">
                  <span class="apf-coord-collab__name"><?php echo esc_html( $entry['provider_name'] ?? '—' ); ?></span>
                  <?php if ( $company_label && $company_label !== ( $entry['provider_name'] ?? '' ) ) : ?>
                    <span class="apf-coord-collab__company"><?php echo esc_html( $company_label ); ?></span>
                  <?php endif; ?>
                  <?php if ( $value_label ) : ?>
                    <span class="apf-coord-collab__value"><?php echo esc_html( $value_label ); ?></span>
                  <?php endif; ?>
                  <span class="apf-coord-collab__status-label"><?php echo esc_html( $status_label ); ?></span>
                </button>
                <div class="apf-coord-collab__actions">
                  <?php if ( 'pending' === $status && ! $options['lock_actions'] ) : ?>
                    <form method="post" class="apf-coord-request__actions">
                      <?php wp_nonce_field( 'apf_coord_request', 'apf_coord_request_nonce' ); ?>
                      <input type="hidden" name="apf_coord_request_id" value="<?php echo esc_attr( $entry['id'] ?? '' ); ?>">
                      <input type="hidden" name="apf_coord_request_note" value="">
                      <noscript>
                        <label class="apf-coord-request__note-noscript">
                          <span>Informe o motivo da recusa</span>
                          <textarea name="apf_coord_request_note_noscript" rows="2" placeholder="Descreva rapidamente o motivo"></textarea>
                        </label>
                        <p class="apf-coord-request__note-hint">Obrigatório para concluir a recusa.</p>
                      </noscript>
                      <button type="submit" name="apf_coord_request_action" value="approve" class="apf-coord-btn apf-coord-btn--success" data-approve-btn>Validar</button>
                      <button type="submit" name="apf_coord_request_action" value="reject" class="apf-coord-btn apf-coord-btn--danger">Recusar</button>
                    </form>
                  <?php else : ?>
                    <p class="apf-coord-collab__note">
                      <?php echo esc_html( $status_label ); ?>
                      <?php if ( $decision_at ) : ?>
                        — <?php echo esc_html( $decision_at ); ?>
                      <?php endif; ?>
                    </p>
                    <?php if ( 'rejected' === $status && ! empty( $entry['decision_note'] ) ) : ?>
                      <p class="apf-coord-collab__note-detail">
                        <strong>Motivo:</strong> <?php echo esc_html( $entry['decision_note'] ); ?>
                      </p>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="apf-coord-collab__body" id="<?php echo esc_attr( $panel_id ); ?>" data-collab-panel="<?php echo esc_attr( $panel_id ); ?>" hidden>
                <?php if ( $sent_at ) : ?>
                  <p class="apf-coord-collab__meta">Solicitação enviada em <?php echo esc_html( $sent_at ); ?></p>
                <?php endif; ?>
                <?php if ( ! empty( $payment_details ) ) : ?>
                  <div class="apf-coord-collab__section">
                    <h5>Informações de pagamento</h5>
                    <dl>
                      <?php foreach ( $payment_details as $label => $value ) : ?>
                        <dt><?php echo esc_html( $label ); ?></dt>
                        <dd><?php echo esc_html( $value ?: '—' ); ?></dd>
                      <?php endforeach; ?>
                    </dl>
                  </div>
                <?php endif; ?>
                <?php if ( ! empty( $service_details ) ) : ?>
                  <div class="apf-coord-collab__section">
                    <h5>Prestação do serviço</h5>
                    <dl>
                      <?php foreach ( $service_details as $label => $value ) : ?>
                        <dt><?php echo esc_html( $label ); ?></dt>
                        <dd class="<?php echo ( 'Descrição do serviço ou material' === $label ) ? 'apf-coord-collab__description' : ''; ?>">
                          <?php
                          if ( 'Descrição do serviço ou material' === $label ) {
                              echo nl2br( esc_html( $value ?: '—' ) );
                          } else {
                              echo esc_html( $value ?: '—' );
                          }
                          ?>
                        </dd>
                      <?php endforeach; ?>
                    </dl>
                  </div>
                <?php endif; ?>
                <?php if ( ! empty( $payout_details ) ) : ?>
                  <div class="apf-coord-collab__section">
                    <h5>Dados para pagamento</h5>
                    <dl>
                      <?php foreach ( $payout_details as $label => $value ) : ?>
                        <dt><?php echo esc_html( $label ); ?></dt>
                        <dd><?php echo esc_html( $value ?: '—' ); ?></dd>
                      <?php endforeach; ?>
                    </dl>
                  </div>
                <?php endif; ?>
                <?php if ( 'rejected' === $status && ! empty( $entry['decision_note'] ) ) : ?>
                  <div class="apf-coord-collab__section">
                    <h5>Motivo da recusa</h5>
                    <p class="apf-coord-collab__description"><?php echo esc_html( $entry['decision_note'] ); ?></p>
                  </div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; endif; ?>
        </div>
        <?php
        return trim( ob_get_clean() );
    }
}

if ( ! function_exists( 'apf_get_submission_payload' ) ) {
    /**
     * Retorna um resumo dos dados da submissão para exibir/filtrar.
     *
     * @param int $post_id
     * @return array<string,string>
     */
    function apf_get_submission_payload( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || 'apf_submission' !== get_post_type( $post_id ) ) {
            return array();
        }
        $meta = function( $key ) use ( $post_id ) {
            return get_post_meta( $post_id, 'apf_' . $key, true );
        };

        $tipo = strtolower( (string) $meta( 'pessoa_tipo' ) );
        $company = $meta( 'nome_empresa' );
        $collab  = ( 'pj' === $tipo )
            ? ( $meta( 'nome_colaborador' ) ?: $meta( 'nome_prof' ) )
            : $meta( 'nome_prof' );
        $nome = $collab ?: $company;
        $doc_id = ( 'pj' === $tipo ) ? $meta( 'cnpj' ) : $meta( 'cpf' );
        $doc_id = $doc_id ? sanitize_text_field( (string) $doc_id ) : '';
        $valor = $meta( 'valor' );
        $valor_fmt = ( '' !== $valor ) ? number_format( (float) $valor, 2, ',', '.' ) : '';
        $banco_parts = array_filter( array_map( 'trim', array(
            (string) $meta( 'banco' ),
            (string) $meta( 'agencia' ),
            (string) $meta( 'conta' ),
        ) ) );
        $banco = $banco_parts ? implode( ' / ', $banco_parts ) : '';

        $provider_payload = array(
            'Tipo do prestador'       => ( 'pj' === $tipo ) ? 'Pessoa Jurídica' : 'Pessoa Física',
            'Empresa (PJ)'            => ( 'pj' === $tipo ) ? ( $company ?: '—' ) : '',
            'Nome do colaborador'     => $nome ?: '—',
            'Documento (CPF/CNPJ)'    => $doc_id ?: '—',
        );

        return array_merge( $provider_payload, array(
            'Data'                => get_post_field( 'post_date', $post_id ),
            'Tipo'                => strtoupper( $tipo ?: '—' ),
            'Nome/Empresa'        => $nome ?: '—',
            'Nome do colaborador' => $collab ?: ( $company ?: '—' ),
            'Empresa (PJ)'        => ( 'pj' === $tipo ) ? ( $company ?: '—' ) : '',
            'Telefone'            => $meta( 'tel_prestador' ) ?: '—',
            'E-mail'              => $meta( 'email_prest' ) ?: '—',
            'Valor (R$)'          => $valor_fmt ?: '—',
            'Coordenador'         => $meta( 'nome_diretor' ) ?: '—',
            'Doc. Fiscal'         => $meta( 'num_doc_fiscal' ) ?: '—',
            'Data do Serviço'     => $meta( 'data_prest' ) ?: '—',
            'Classificação'       => $meta( 'classificacao' ) ?: '—',
            'Curso'               => $meta( 'nome_curto' ) ?: '—',
            'Carga Horária (CH)'  => $meta( 'carga_horaria' ) ?: '—',
            'Banco/Agência/Conta' => $banco ?: '—',
            '_admin_url'          => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
        ) );
    }
}

/* ====== CPT para armazenar as submissões ====== */
add_action('init', function () {
    register_post_type('apf_submission', [
        'labels' => [
            'name'          => 'Submissões FAEPA',
            'singular_name' => 'Submissão FAEPA',
            'menu_name'     => 'FAEPA Submissões',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => true,
        'supports'     => ['title'],
    ]);
});

/** Retorna a submissão mais recente do usuário (ou 0 se não houver). */
function apf_get_user_submission_id( $user_id, $email_prest = '' ){
    $q = new WP_Query([
        'post_type'      => 'apf_submission',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'author'         => (int) $user_id,
        'fields'         => 'ids',
    ]);
    if ( $q->have_posts() && ! empty($q->posts[0]) ) return (int) $q->posts[0];

    if ( $email_prest ) {
        $q2 = new WP_Query([
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
        if ( $q2->have_posts() && ! empty($q2->posts[0]) ) return (int) $q2->posts[0];
    }
    return 0;
}

if ( ! function_exists( 'apf_render_login_card' ) ) {
    /**
     * Renderiza um cartão de login elegante reutilizado pelo formulário público e portal.
     */
    function apf_render_login_card( $args = array() ) {
        if ( ! function_exists( 'wp_login_form' ) ) {
            return '<p>Faça login para continuar.</p>';
        }

        $defaults = array(
            'redirect'       => isset( $_SERVER['REQUEST_URI'] ) ? esc_url( $_SERVER['REQUEST_URI'] ) : home_url(),
            'title'          => 'Acesse sua conta',
            'description'    => 'Use sua conta FAEPA ou cadastre-se rapidamente para continuar o envio.',
        );
        $args = wp_parse_args( $args, $defaults );

        $form = wp_login_form( array(
            'echo'           => false,
            'redirect'       => $args['redirect'],
            'remember'       => true,
            'label_username' => 'Usuário ou e-mail',
            'label_password' => 'Senha',
            'label_remember' => 'Manter conectado',
            'label_log_in'   => 'Entrar',
            'form_id'        => 'apf-login-form',
        ) );

        static $printed_css = false;
        $css = '';
        if ( ! $printed_css ) {
            $printed_css = true;
            $css = '
            <style>
              .apf-login-card{max-width:420px;margin:48px auto;border-radius:20px;background:#ffffff;box-shadow: 0 18px 35px rgb(0 0 0 / 65%);;color:#0f172a;padding:36px;}
              .apf-login-card__inner{padding:0;}
              .apf-login-card__badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;background:rgba(25,118,210,.12);color:#0d47a1;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;}
              .apf-login-card h2{margin:0;font-size:24px;color:#0f172a;}
              .apf-login-card p{margin:8px 0 0;font-size:13.5px;color:#475467;}
              .login-remember {width: 13px; white-space: nowrap;}
              #apf-login-form{margin-top:24px;display:grid;gap:18px;}
              #apf-login-form p{margin:0;}
              #apf-login-form label{display:flex;font-size:13px;font-weight:600;color:#1d2939;gap:6px;}
              #apf-login-form input[type="text"],#apf-login-form input[type="password"]{border:1px solid #d0d5dd;border-radius:12px;padding:12px 14px;font-size:14px;width:100%;transition:border-color .18s ease, box-shadow .18s ease;}
              #apf-login-form input[type="text"]:focus,#apf-login-form input[type="password"]:focus{border-color:#1976d2;box-shadow:0 0 0 4px rgba(25,118,210,.18);outline:none;}
              #apf-login-form .forgetmenot{display:flex;align-items:center;gap:8px;font-size:13px;color:#475467;margin-top:4px;}
              #apf-login-form .forgetmenot label{flex-direction:row;align-items:center;font-size:13px;font-weight:500;gap:8px;margin:0;}
              #apf-login-form .forgetmenot input{margin:0;}
              #apf-login-form .submit{margin:0;}
              #apf-login-form .submit input{width:100%;border:none;border-radius:12px;padding:12px 16px;font-size:15px;font-weight:600;background:#1976d2;color:#fff;cursor:pointer;transition:background .2s ease, box-shadow .2s ease;}
              #apf-login-form .submit input:hover{background:#0d47a1;box-shadow:0 12px 24px rgba(13,71,161,.28);}
              .apf-login-card__footer{margin-top:16px;display:flex;justify-content:space-between;gap:12px;font-size:13px;}
              .apf-login-card__footer a{color:#1976d2;text-decoration:none;font-weight:600;}
              .apf-login-card__footer a:hover{text-decoration:underline;}
              @media(max-width:540px){
                .apf-login-card{margin:32px 16px;padding:30px 26px;}
                .apf-login-card__inner{padding:28px 22px;}
                .apf-login-card__footer{flex-direction:column;align-items:flex-start;}
              }
            </style>';
        }

        $register_url = esc_url( wp_registration_url() );
        $lost_url     = esc_url( wp_lostpassword_url() );

        return $css . '
        <div class="apf-login-card" aria-live="polite">
          <div class="apf-login-card__inner">
            <span class="apf-login-card__badge">Área Restrita</span>
            <h2>' . esc_html( $args['title'] ) . '</h2>
            <p>' . esc_html( $args['description'] ) . '</p>
            ' . $form . '
            <div class="apf-login-card__footer">
              <a href="' . $register_url . '">Criar uma conta</a>
              <a href="' . $lost_url . '">Esqueci minha senha</a>
            </div>
          </div>
        </div>';
    }
}

/* ====== Shortcode do formulário (3 abas) ====== */
add_shortcode('apf_form', function () {
    if ( ! is_user_logged_in() ) {
        return apf_render_login_card();
    }

    $out = '';
    $nome_diretor = '';

    $apf_directors = get_option('apf_directors', []);
    if ( ! is_array($apf_directors) ) {
        $apf_directors = [];
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
    $apf_director_names = [];
    foreach ( $apf_directors as $entry ) {
        if ( isset($entry['director']) ) {
            $name = trim((string) $entry['director']);
            if ( $name !== '' ) {
                $apf_director_names[$name] = true;
            }
        }
    }

    // Processa envio (AGORA: sempre cria um novo registro)
    if ( ! empty($_POST['apf_submit']) ) {
        if ( ! isset($_POST['apf_nonce']) || ! wp_verify_nonce($_POST['apf_nonce'], 'apf_form_nonce') ) {
            return '<div style="color:#b00020">Falha de segurança. Recarregue a página.</div>';
        }

        // Helpers
        $only_num = function($v){ return preg_replace('/\D+/', '', (string)$v); };
        $clean    = function($v){ return sanitize_text_field($v); };

        // ====== CAPTURA E SANITIZA ======
        // Passo 1
        $nome_diretor   = $clean( wp_unslash($_POST['nome_diretor']   ?? '') );
        $num_controle   = $only_num( wp_unslash($_POST['num_controle']   ?? '') );
        $tel_prestador  = $only_num( wp_unslash($_POST['tel_prestador']  ?? '') );
        $email_prest    = sanitize_email( wp_unslash($_POST['email_prest'] ?? '') );
        $num_doc_fiscal = $clean( wp_unslash($_POST['num_doc_fiscal'] ?? '') );
        $valor_bruto    = wp_unslash($_POST['valor'] ?? '');
        $valor_norm     = apf_normalize_currency_value( $valor_bruto );

        $pessoa_tipo       = (isset($_POST['pessoa_tipo']) && $_POST['pessoa_tipo']==='pf') ? 'pf' : 'pj';
        $nome_empresa      = $clean( wp_unslash($_POST['nome_empresa'] ?? '') );
        $nome_colaborador  = $clean( wp_unslash($_POST['nome_colaborador'] ?? '') );
        $cnpj              = $only_num( wp_unslash($_POST['cnpj'] ?? '') );
        $nome_prof         = $clean( wp_unslash($_POST['nome_prof'] ?? '') );
        $cpf               = $only_num( wp_unslash($_POST['cpf'] ?? '') );

        // Passo 2
        $prest_contas  = $clean( wp_unslash($_POST['prest_contas'] ?? '') );
        $data_prest    = $clean( wp_unslash($_POST['data_prest'] ?? '') );
        $classificacao = $clean( wp_unslash($_POST['classificacao'] ?? '') );
        $descricao     = sanitize_textarea_field( wp_unslash($_POST['descricao'] ?? '') );
        $nome_curto    = $clean( wp_unslash($_POST['nome_curto'] ?? '') );
        $carga_horaria = $only_num( wp_unslash($_POST['carga_horaria'] ?? '') );

        // Passo 3
        $banco   = $clean( wp_unslash($_POST['banco']   ?? '') );
        $agencia = $only_num( wp_unslash($_POST['agencia'] ?? '') );
        $conta   = $only_num( wp_unslash($_POST['conta']   ?? '') );

        // Fallback e-mail do usuário logado
        if ( empty($email_prest) ) {
            $u = wp_get_current_user();
            if ( $u && ! empty($u->user_email) ) {
                $email_prest = sanitize_email($u->user_email);
            }
        }

        // ====== SEMPRE CRIA NOVO POST ======
        $user_id   = get_current_user_id();
        $titulo    = 'Solicitação - ' . ( $pessoa_tipo==='pj'
                        ? ( $nome_colaborador ?: $nome_empresa ?: 'PJ' )
                        : ( $nome_prof    ?: 'PF' )
                     ) . ' - ' . current_time('Y-m-d H:i');

        $post_id = wp_insert_post([
            'post_type'     => 'apf_submission',
            'post_title'    => $titulo,
            'post_status'   => 'publish',
            'post_author'   => $user_id,
            // força a data "agora" pra subir no topo
            'post_date'     => current_time('mysql'),
            'post_date_gmt' => get_gmt_from_date( current_time('mysql') ),
        ]);

        if ( $post_id && ! is_wp_error($post_id) ) {
            // Grava metas
            $meta = [
                // passo 1
                'nome_diretor'   => $nome_diretor,
                'num_controle'   => $num_controle,
                'tel_prestador'  => $tel_prestador,
                'email_prest'    => $email_prest,
                'num_doc_fiscal' => $num_doc_fiscal,
                'valor'          => $valor_norm,
                'pessoa_tipo'    => $pessoa_tipo,
                'nome_empresa'   => $nome_empresa,
                'nome_colaborador'=> $nome_colaborador,
                'cnpj'           => $cnpj,
                'nome_prof'      => $nome_prof,
                'cpf'            => $cpf,
                // passo 2
                'prest_contas'   => $prest_contas,
                'data_prest'     => $data_prest,
                'classificacao'  => $classificacao,
                'descricao'      => $descricao,
                'nome_curto'     => $nome_curto,
                'carga_horaria'  => $carga_horaria,
                // passo 3
                'banco'          => $banco,
                'agencia'        => $agencia,
                'conta'          => $conta,
            ];
            foreach ($meta as $k => $v) {
                update_post_meta($post_id, 'apf_'.$k, $v);
            }

            $out .= '<div style="padding:12px;border:1px solid #cde;border-radius:8px;background:#f7fbff;margin-bottom:16px">Formulário enviado com sucesso.</div>';
        } else {
            $out .= '<div style="padding:12px;border:1px solid #f3c;border-radius:8px;background:#fff5f8;margin-bottom:16px;color:#b00020">Não foi possível enviar agora.</div>';
        }
    }

    // ====== FORM HTML (3 abas) ======
    ob_start(); ?>
    <div class="apf-card">
      <h2>Autorização de Pagamento</h2>

      <form method="post" id="apfForm" novalidate>
        <?php wp_nonce_field('apf_form_nonce','apf_nonce'); ?>
        <input type="hidden" name="apf_submit" value="1">

        <div class="apf-steps">
          <div class="apf-step is-active">1. Informações do Pagamento</div>
          <div class="apf-step">2. Prestação do serviço</div>
          <div class="apf-step">3. Dados para pagamento</div>
        </div>

        <!-- STEP 1 -->
        <section class="apf-pane is-active" data-step="1">
          <div class="apf-grid">
            <label>Nome Completo Diretor Executivo
              <?php if ( ! empty($apf_directors) ) : ?>
                <select name="nome_diretor" required>
                  <option value="" disabled <?php echo selected($nome_diretor, '', false); ?>>Selecione um diretor</option>
                  <?php foreach ( $apf_directors as $dir_entry ) :
                      $dir_name = isset($dir_entry['director']) ? trim((string) $dir_entry['director']) : '';
                      if ( $dir_name === '' ) { continue; }
                      $dir_course = isset($dir_entry['course']) ? trim((string) $dir_entry['course']) : '';
                      $label = $dir_course ? $dir_name . ' — ' . $dir_course : $dir_name;
                      $selected = selected($nome_diretor, $dir_name, false);
                  ?>
                    <option value="<?php echo esc_attr($dir_name); ?>" data-course="<?php echo esc_attr($dir_course); ?>" data-full-label="<?php echo esc_attr($label); ?>" data-director-label="<?php echo esc_attr($dir_name); ?>" title="<?php echo esc_attr($label); ?>" <?php echo $selected; ?>>
                      <?php echo esc_html($label); ?>
                    </option>
                  <?php endforeach; ?>
                  <?php if ( $nome_diretor !== '' && empty($apf_director_names[$nome_diretor]) ) : ?>
                    <option value="<?php echo esc_attr($nome_diretor); ?>" data-course="" data-full-label="<?php echo esc_attr($nome_diretor . ' (manual)'); ?>" data-director-label="<?php echo esc_attr($nome_diretor); ?>" selected title="<?php echo esc_attr($nome_diretor); ?>">
                      <?php echo esc_html($nome_diretor . ' (manual)'); ?>
                    </option>
                  <?php endif; ?>
                </select>
              <?php else : ?>
                <input type="text" name="nome_diretor" required>
                <span class="apf-field-note">Cadastre diretores no painel financeiro para habilitar a seleção.</span>
              <?php endif; ?>
            </label>

            <label>Número de Controle Secretaria *
              <input type="text" name="num_controle" inputmode="numeric" maxlength="20" required>
            </label>

            <label>Telefone do Prestador *
              <input type="tel" name="tel_prestador" placeholder="(00) 00000-0000" required>
            </label>

            <label>E-mail do Prestador *
              <input type="email" name="email_prest" required>
            </label>

            <label>Número do Documento Fiscal
              <input type="text" name="num_doc_fiscal">
            </label>

            <label>Valor (R$) *
              <input type="text" name="valor" placeholder="0,00" required>
            </label>
          </div>

          <fieldset class="apf-row">
            <legend>Pessoa</legend>
            <label class="apf-radio">
              <input type="radio" name="pessoa_tipo" value="pj" checked> Pessoa Jurídica (PJ)
            </label>
            <label class="apf-radio">
              <input type="radio" name="pessoa_tipo" value="pf"> Pessoa Física (PF)
            </label>
          </fieldset>

          <div class="apf-grid" id="apfPJ">
            <label>Nome da Empresa
              <input type="text" name="nome_empresa">
            </label>
            <label>Nome do colaborador
              <input type="text" name="nome_colaborador">
            </label>
            <label>CNPJ
              <input type="text" name="cnpj" placeholder="00.000.000/0000-00">
            </label>
          </div>

          <div class="apf-grid" id="apfPF" style="display:none">
            <label>Nome do Profissional
              <input type="text" name="nome_prof">
            </label>
            <label>CPF
              <input type="text" name="cpf" placeholder="000.000.000-00">
            </label>
          </div>

          <div class="apf-actions">
            <button type="button" class="apf-next">Próximo</button>
          </div>
        </section>

        <!-- STEP 2 -->
        <section class="apf-pane" data-step="2">
          <div class="apf-grid">
            <label>Prestação de contas
              <input type="text" name="prest_contas">
            </label>

            <label>Data de prestação de serviço
              <input type="date" name="data_prest">
            </label>

            <label>Classificação
              <input type="text" name="classificacao">
            </label>

            <label>Descrição do serviço ou material
              <textarea name="descricao" rows="3"></textarea>
            </label>

            <label>Nome curto do curso
              <input type="text" name="nome_curto">
            </label>

            <label>Carga horária do curso
              <input type="text" name="carga_horaria" inputmode="numeric" maxlength="5" placeholder="ex: 8">
            </label>
          </div>

          <div class="apf-actions">
            <button type="button" class="apf-prev">Voltar</button>
            <button type="button" class="apf-next">Próximo</button>
          </div>
        </section>

        <!-- STEP 3 -->
        <section class="apf-pane" data-step="3">
          <div class="apf-grid">
            <label>Banco
              <input type="text" name="banco">
            </label>
            <label>Agência
              <input type="text" name="agencia" inputmode="numeric" maxlength="10" placeholder="0000-0">
            </label>
            <label>Conta Corrente
              <input type="text" name="conta" inputmode="numeric" maxlength="20" placeholder="000000-0">
            </label>
          </div>

          <div class="apf-actions">
            <button type="button" class="apf-prev">Voltar</button>
            <button type="submit" class="apf-submit">Enviar</button>
          </div>
        </section>
      </form>
    </div>

    <style>
      .apf-card{max-width:640px;margin:16px auto;padding:20px 22px;background:#fff;border-radius:12px;box-shadow:0 10px 28px rgba(0,0,0,.5);border: 1px solid #8d8d8d;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial}
      .apf-card h2{margin:0 0 14px;font-size:22px;text-align:center}
      .apf-steps{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin:10px 0 16px}
      .apf-step{background:#f2f4f7;border:1px solid #e6e9ef;border-radius:10px;padding:10px 12px;font-size:12.5px;text-align:center;color:#475467}
      .apf-step.is-active{background:#e8f1ff;border-color:#bcd4ff;color:#1849a9;font-weight:600}
      .apf-pane{display:none}
      .apf-pane.is-active{display:block}
      .apf-grid{display:grid;grid-template-columns:1fr;gap:12px}
      .apf-grid label{display:flex;flex-direction:column;font-size:13px;color:#344054}
      .apf-grid input,.apf-grid textarea,.apf-grid select{border:1px solid #d0d5dd;border-radius:10px;padding:10px 12px;font-size:14px;background:#fff;color:#344054;width:100%;max-width:100%;box-sizing:border-box}
      .apf-grid select{appearance:none;-webkit-appearance:none;-moz-appearance:none;background-image:linear-gradient(45deg,transparent 50%,#475067 50%),linear-gradient(135deg,#475067 50%,transparent 50%),linear-gradient(to right,transparent,transparent);background-position:calc(100% - 18px) 55%,calc(100% - 13px) 55%,100% 0;background-size:5px 5px,5px 5px,40px 100%;background-repeat:no-repeat;padding-right:42px}
      .apf-row{display:flex;gap:18px;border:none;margin:10px 0 0;padding:0}
      .apf-radio{display:flex;align-items:center;gap:6px;font-size:14px}
      .apf-actions{display:flex;justify-content:space-between;margin-top:16px}
      .apf-actions button{background:#1f6feb;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer}
      .apf-actions .apf-prev{background:#a0a7b4}
      .apf-field-note{display:block;font-size:12px;color:#b42318;margin-top:6px}
      @media(min-width:640px){ .apf-grid{grid-template-columns:1fr 1fr} }
    </style>

    <script>
    (function(){
      const form  = document.getElementById('apfForm');
      const panes = Array.from(form.querySelectorAll('.apf-pane'));
      const steps = Array.from(form.querySelectorAll('.apf-step'));
      let current = 0;

      function showStep(i){
        panes.forEach((p,idx)=>p.classList.toggle('is-active', idx===i));
        steps.forEach((s,idx)=>s.classList.toggle('is-active', idx===i));
        current = i;
        window.scrollTo({top: form.getBoundingClientRect().top + window.scrollY - 20, behavior:'smooth'});
      }

      form.addEventListener('click', function(e){
        if(e.target.classList.contains('apf-next')){
          if ( validatePane(panes[current]) ) showStep(Math.min(current+1, panes.length-1));
        }
        if(e.target.classList.contains('apf-prev')){
          showStep(Math.max(current-1, 0));
        }
      });

      function validatePane(pane){
        const req = Array.from(pane.querySelectorAll('[required]'));
        for(const el of req){
          if(!el.value.trim()){ el.focus(); el.reportValidity && el.reportValidity(); return false; }
        }
        return true;
      }

      // PF/PJ
      const radios = form.querySelectorAll('input[name="pessoa_tipo"]');
      const boxPF = document.getElementById('apfPF');
      const boxPJ = document.getElementById('apfPJ');
      function togglePessoa(){
        const v = form.querySelector('input[name="pessoa_tipo"]:checked').value;
        const pf = v === 'pf';
        boxPF.style.display = pf ? '' : 'none';
        boxPJ.style.display = pf ? 'none' : '';
        form.querySelector('input[name="nome_prof"]').required = pf;
        form.querySelector('input[name="cpf"]').required = pf;
        form.querySelector('input[name="nome_empresa"]').required = !pf;
        form.querySelector('input[name="nome_colaborador"]').required = !pf;
        form.querySelector('input[name="cnpj"]').required = !pf;
      }
      radios.forEach(r => r.addEventListener('change', togglePessoa));
      togglePessoa();

      const directorSelect = form.querySelector('select[name="nome_diretor"]');
      const courseInput = form.querySelector('input[name="nome_curto"]');
      if (directorSelect) {
        const syncCourse = () => {
          if (!courseInput) return;
          const option = directorSelect.options[directorSelect.selectedIndex];
          if (!option) return;
          const course = option.getAttribute('data-course') || '';
          if (course) {
            courseInput.value = course;
          }
        };
        const restoreFullLabels = () => {
          Array.from(directorSelect.options).forEach(opt => {
            const fullLabel = opt.getAttribute('data-full-label');
            if (fullLabel !== null) {
              opt.textContent = fullLabel;
            }
          });
        };
        const applyDirectorLabels = () => {
          restoreFullLabels();
          const current = directorSelect.options[directorSelect.selectedIndex];
          if (current) {
            const directorLabel = current.getAttribute('data-director-label');
            if (directorLabel) {
              current.textContent = directorLabel;
            }
          }
        };
        directorSelect.addEventListener('change', () => {
          syncCourse();
          applyDirectorLabels();
        });
        directorSelect.addEventListener('focus', restoreFullLabels);
        directorSelect.addEventListener('blur', applyDirectorLabels);
        if (!courseInput || !courseInput.value) {
          syncCourse();
        }
        applyDirectorLabels();
      }

      // Máscaras
      const onlyNum = v => v.replace(/\D+/g,'');
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
        v = (parseInt(v,10)).toString();
        while(v.length < 3) v = '0'+v;
        const cents = v.slice(-2);
        const ints  = v.slice(0,-2).replace(/\B(?=(\d{3})+(?!\d))/g,'.');
        el.value = ints + ',' + cents;
      }

      form.querySelector('input[name="tel_prestador"]').addEventListener('input', e => maskPhone(e.target));
      form.querySelector('input[name="cpf"]').addEventListener('input', e => maskCPF(e.target));
      form.querySelector('input[name="cnpj"]').addEventListener('input', e => maskCNPJ(e.target));
      form.querySelector('input[name="num_controle"]').addEventListener('input', e => maskInt(e.target, 20));
      form.querySelector('input[name="carga_horaria"]').addEventListener('input', e => maskInt(e.target, 5));
      form.querySelector('input[name="agencia"]').addEventListener('input', e => maskInt(e.target, 10));
      form.querySelector('input[name="conta"]').addEventListener('input', e => maskInt(e.target, 20));
      form.querySelector('input[name="valor"]').addEventListener('input', e => maskMoney(e.target));

      showStep(0);
    })();
    </script>
    <?php
    $out .= ob_get_clean();
    return $out;
});

/* ====== includes ====== */
require_once __DIR__ . '/includes/financeiro.php';
require_once __DIR__ . '/includes/admin-meta.php';
require_once __DIR__ . '/includes/portal_colaborador.php';
require_once __DIR__ . '/includes/portal_coordenador.php';
require_once __DIR__ . '/includes/portal_faepa.php';
