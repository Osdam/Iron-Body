@php
    /**
     * Comprobante electrónico — correo de marca Iron Body Neiva.
     * SOLO presentación: layout en tablas + estilos inline para máxima
     * compatibilidad con Gmail (web y móvil), Outlook y Apple Mail.
     * Paleta: negro profundo, dorado Iron Body, blanco, verde solo para "validada".
     */
    $gold   = '#F4C430'; // dorado Iron Body
    $black  = '#0B0B0C'; // negro profundo
    $ink    = '#15161A'; // gris casi negro (card oscura)
    $green  = '#16A34A'; // verde estado validada
    $muted  = '#6B7280'; // texto secundario
    $line   = '#E6E7EB'; // separadores
@endphp
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>Factura electrónica — Iron Body Neiva</title>
    <style>
        /* Reset mínimo seguro para clientes de correo */
        body { margin:0; padding:0; width:100% !important; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
        table { border-collapse:collapse !important; }
        img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
        a { text-decoration:none; }
        /* Responsive para Gmail móvil */
        @media only screen and (max-width:620px) {
            .ib-container { width:100% !important; }
            .ib-pad { padding-left:22px !important; padding-right:22px !important; }
            .ib-title { font-size:24px !important; line-height:30px !important; }
            .ib-total { font-size:26px !important; }
            .ib-stack { display:block !important; width:100% !important; }
            .ib-stack-gap { height:12px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#0F1012;">
    {{-- Preheader oculto (resumen en la bandeja de entrada) --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">
        Tu factura electrónica Iron Body Neiva fue validada ante la DIAN. PDF y XML adjuntos.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0F1012;">
        <tr>
            <td align="center" style="padding:32px 14px;">

                {{-- ============ CONTENEDOR CENTRAL ============ --}}
                <table role="presentation" class="ib-container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#FFFFFF; border-radius:18px; overflow:hidden; box-shadow:0 18px 50px rgba(0,0,0,0.35);">

                    {{-- ============ HEADER NEGRO + LOGO ============ --}}
                    <tr>
                        <td align="center" style="background-color:{{ $black }}; padding:36px 30px 32px 30px;">
                            @if(!empty($logoUrl))
                                <img src="{{ $logoUrl }}" alt="Iron Body Neiva" width="180" style="display:block; width:180px; max-width:72%; height:auto; margin:0 auto;">
                            @else
                                <div style="font-family:'Trebuchet MS', Arial, Helvetica, sans-serif; font-size:30px; line-height:1; font-weight:800; letter-spacing:5px; color:#FFFFFF; text-transform:uppercase;">
                                    IRON <span style="color:{{ $gold }};">BODY</span>
                                </div>
                                <div style="font-family:Arial, Helvetica, sans-serif; font-size:11px; letter-spacing:5px; color:#9CA0A8; text-transform:uppercase; margin-top:8px;">
                                    Neiva
                                </div>
                            @endif
                        </td>
                    </tr>

                    {{-- ============ FRANJA DORADA DE ACENTO ============ --}}
                    <tr>
                        <td style="height:4px; background-color:{{ $gold }}; line-height:4px; font-size:0;">&nbsp;</td>
                    </tr>

                    {{-- ============ CUERPO ============ --}}
                    <tr>
                        <td class="ib-pad" style="padding:40px 44px 8px 44px;">

                            {{-- Badge estado --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="background-color:#E9F8EF; border:1px solid #B7E6C8; border-radius:999px; padding:7px 16px;">
                                        <span style="font-family:Arial, Helvetica, sans-serif; font-size:11px; font-weight:700; letter-spacing:1.2px; color:{{ $green }}; text-transform:uppercase;">
                                            &#10003;&nbsp; Validada ante la DIAN
                                        </span>
                                    </td>
                                </tr>
                            </table>

                            {{-- Título --}}
                            <h1 class="ib-title" style="margin:22px 0 0 0; font-family:'Trebuchet MS', Arial, Helvetica, sans-serif; font-size:28px; line-height:34px; font-weight:800; color:{{ $black }};">
                                Tu factura electrónica ha sido generada
                            </h1>

                            {{-- Mensaje --}}
                            <p style="margin:16px 0 0 0; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:24px; color:#4B4F58;">
                                Hola, adjuntamos tu factura electrónica emitida por
                                <strong style="color:{{ $black }};">Iron Body Neiva</strong>.
                                Encontrarás el PDF y XML anexos a este correo.
                            </p>
                        </td>
                    </tr>

                    {{-- ============ CARD DE RESUMEN (oscura premium) ============ --}}
                    <tr>
                        <td class="ib-pad" style="padding:26px 44px 6px 44px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $ink }}; border-radius:14px;">
                                <tr>
                                    <td style="padding:26px 26px 10px 26px;">
                                        <span style="font-family:Arial, Helvetica, sans-serif; font-size:11px; font-weight:700; letter-spacing:1.5px; color:{{ $gold }}; text-transform:uppercase;">
                                            Resumen de tu comprobante
                                        </span>
                                    </td>
                                </tr>

                                {{-- Número + Total (dos columnas que apilan en móvil) --}}
                                <tr>
                                    <td style="padding:6px 26px 0 26px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td class="ib-stack" width="50%" valign="top" style="padding:10px 0;">
                                                    <div style="font-family:Arial, Helvetica, sans-serif; font-size:11px; letter-spacing:.8px; color:#8A8F99; text-transform:uppercase;">Número de factura</div>
                                                    <div style="font-family:'Trebuchet MS', Arial, Helvetica, sans-serif; font-size:18px; font-weight:700; color:#FFFFFF; margin-top:5px;">
                                                        {{ $fullNumber ?: '—' }}
                                                    </div>
                                                </td>
                                                <td class="ib-stack" width="50%" valign="top" style="padding:10px 0;">
                                                    <div style="font-family:Arial, Helvetica, sans-serif; font-size:11px; letter-spacing:.8px; color:#8A8F99; text-transform:uppercase;">Total</div>
                                                    <div class="ib-total" style="font-family:'Trebuchet MS', Arial, Helvetica, sans-serif; font-size:22px; font-weight:800; color:{{ $gold }}; margin-top:5px;">
                                                        {{ $currency }} {{ $total }}
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                @if($validatedAt)
                                {{-- Separador --}}
                                <tr><td style="padding:6px 26px;"><div style="height:1px; background-color:#2A2C33; line-height:1px; font-size:0;">&nbsp;</div></td></tr>
                                <tr>
                                    <td style="padding:4px 26px 0 26px;">
                                        <div style="font-family:Arial, Helvetica, sans-serif; font-size:11px; letter-spacing:.8px; color:#8A8F99; text-transform:uppercase;">Fecha de validación</div>
                                        <div style="font-family:Arial, Helvetica, sans-serif; font-size:15px; font-weight:600; color:#EDEEF1; margin-top:5px;">
                                            {{ $validatedAt }}
                                        </div>
                                    </td>
                                </tr>
                                @endif

                                @if($cufe)
                                {{-- Separador --}}
                                <tr><td style="padding:14px 26px 6px 26px;"><div style="height:1px; background-color:#2A2C33; line-height:1px; font-size:0;">&nbsp;</div></td></tr>
                                <tr>
                                    <td style="padding:2px 26px 26px 26px;">
                                        <div style="font-family:Arial, Helvetica, sans-serif; font-size:11px; letter-spacing:.8px; color:#8A8F99; text-transform:uppercase;">CUFE</div>
                                        <div style="margin-top:8px; background-color:#0E0F12; border:1px solid #2A2C33; border-radius:9px; padding:12px 14px;">
                                            <span style="font-family:'Courier New', Consolas, monospace; font-size:12px; line-height:18px; color:#C7CAD1; word-break:break-all; overflow-wrap:anywhere;">{{ $cufe }}</span>
                                        </div>
                                    </td>
                                </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    @if($hasPdf || $hasXml)
                    {{-- ============ ADJUNTOS (chips) ============ --}}
                    <tr>
                        <td class="ib-pad" style="padding:24px 44px 6px 44px;">
                            <div style="font-family:Arial, Helvetica, sans-serif; font-size:11px; font-weight:700; letter-spacing:1.2px; color:{{ $muted }}; text-transform:uppercase; margin-bottom:12px;">
                                Archivos adjuntos
                            </div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    @if($hasPdf)
                                    <td class="ib-stack" width="50%" valign="top" style="padding-right:8px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FFFBEF; border:1px solid #F0E2B6; border-radius:11px;">
                                            <tr>
                                                <td width="44" valign="middle" style="padding:14px 0 14px 14px;">
                                                    <div style="width:34px; height:34px; background-color:{{ $black }}; border-radius:8px; text-align:center;">
                                                        <span style="font-family:Arial, Helvetica, sans-serif; font-size:10px; font-weight:800; color:{{ $gold }}; line-height:34px;">PDF</span>
                                                    </div>
                                                </td>
                                                <td valign="middle" style="padding:14px 14px 14px 10px;">
                                                    <div style="font-family:Arial, Helvetica, sans-serif; font-size:14px; font-weight:700; color:{{ $black }};">Factura PDF</div>
                                                    <div style="font-family:Arial, Helvetica, sans-serif; font-size:12px; color:{{ $muted }}; margin-top:2px;">Adjunto a este correo</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    @endif
                                    @if($hasPdf && $hasXml)
                                    <td class="ib-stack-gap" style="font-size:0; line-height:0; height:0;">&nbsp;</td>
                                    @endif
                                    @if($hasXml)
                                    <td class="ib-stack" width="50%" valign="top" style="padding-left:8px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F4F6F9; border:1px solid #E2E6EC; border-radius:11px;">
                                            <tr>
                                                <td width="44" valign="middle" style="padding:14px 0 14px 14px;">
                                                    <div style="width:34px; height:34px; background-color:{{ $black }}; border-radius:8px; text-align:center;">
                                                        <span style="font-family:Arial, Helvetica, sans-serif; font-size:10px; font-weight:800; color:#FFFFFF; line-height:34px;">XML</span>
                                                    </div>
                                                </td>
                                                <td valign="middle" style="padding:14px 14px 14px 10px;">
                                                    <div style="font-family:Arial, Helvetica, sans-serif; font-size:14px; font-weight:700; color:{{ $black }};">Factura XML</div>
                                                    <div style="font-family:Arial, Helvetica, sans-serif; font-size:12px; color:{{ $muted }}; margin-top:2px;">Adjunto a este correo</div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    @endif
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif

                    {{-- ============ NOTA DE AYUDA ============ --}}
                    <tr>
                        <td class="ib-pad" style="padding:26px 44px 36px 44px;">
                            <div style="height:1px; background-color:{{ $line }}; line-height:1px; font-size:0; margin-bottom:22px;">&nbsp;</div>
                            <p style="margin:0; font-family:Arial, Helvetica, sans-serif; font-size:13px; line-height:21px; color:{{ $muted }};">
                                Este comprobante fue validado ante la DIAN. Si tienes alguna duda,
                                simplemente responde a este correo y con gusto te ayudamos.
                            </p>
                        </td>
                    </tr>

                    {{-- ============ FOOTER NEGRO ============ --}}
                    <tr>
                        <td align="center" style="background-color:{{ $black }}; padding:30px 30px;">
                            <div style="font-family:'Trebuchet MS', Arial, Helvetica, sans-serif; font-size:17px; font-weight:800; letter-spacing:2px; color:#FFFFFF; text-transform:uppercase;">
                                IRON <span style="color:{{ $gold }};">BODY</span> NEIVA
                            </div>
                            <div style="font-family:Arial, Helvetica, sans-serif; font-size:12px; letter-spacing:1px; color:#9CA0A8; text-transform:uppercase; margin-top:6px;">
                                Centro médico deportivo
                            </div>
                            <div style="margin-top:14px;">
                                <a href="mailto:{{ $supportEmail }}" style="font-family:Arial, Helvetica, sans-serif; font-size:13px; font-weight:600; color:{{ $gold }};">{{ $supportEmail }}</a>
                            </div>
                            <div style="font-family:Arial, Helvetica, sans-serif; font-size:11px; line-height:17px; color:#7B7F88; margin-top:16px; max-width:420px;">
                                Este correo fue generado automáticamente.
                                Si tienes dudas, responde a este mensaje.
                            </div>
                        </td>
                    </tr>

                </table>
                {{-- ============ /CONTENEDOR ============ --}}

                <div style="font-family:Arial, Helvetica, sans-serif; font-size:11px; color:#5A5E66; margin-top:20px;">
                    &copy; {{ date('Y') }} Iron Body Neiva — Todos los derechos reservados.
                </div>

            </td>
        </tr>
    </table>
</body>
</html>
