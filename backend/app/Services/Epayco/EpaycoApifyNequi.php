<?php

namespace App\Services\Epayco;

use Epayco\Resource;

/**
 * Recurso Nequi por APIFY de ePayco.
 *
 * El SDK oficial `epayco/epayco-php` trae Daviplata (`/payment/process/daviplata`)
 * pero NO un recurso Nequi. Esta clase extiende el `Resource` del SDK y reutiliza
 * EXACTAMENTE su mismo transporte/autenticación APIFY (login + AES + headers) que
 * usa Daviplata, apuntando a la ruta Nequi configurada
 * (`services.epayco.nequi_path`). Así no se modifica el vendor ni se reimplementa
 * la firma/login de ePayco.
 *
 * Igual que Daviplata: crea la transacción y queda PENDIENTE hasta la
 * confirmación real (webhook/consulta). Nunca aprueba localmente.
 */
class EpaycoApifyNequi extends Resource
{
    /**
     * Crea el cobro Nequi (push a la app Nequi del cliente).
     *
     * @param  array|null  $options  doc_type, document, name, last_name, email,
     *                               indicative, phone, value, tax, tax_base,
     *                               currency, dues, ip, description, invoice,
     *                               url_response, url_confirmation, test
     * @return mixed  Respuesta cruda del SDK (stdClass/array/json).
     */
    public function create($options = null)
    {
        $path = (string) config('services.epayco.nequi_path', '/payment/process/pmpush');

        // Misma firma que Daviplata::create() del SDK: APIFY (último arg = true),
        // sin switch (penúltimos en false). Las llaves salen del objeto Epayco.
        return $this->request(
            'POST',
            $path,
            $this->epayco->api_key,
            $options,
            $this->epayco->private_key,
            $this->epayco->test,
            false,            // switch
            $this->epayco->lang,
            false,
            false,
            true              // apify
        );
    }
}
