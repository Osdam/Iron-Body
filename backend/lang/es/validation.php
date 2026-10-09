<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MENSAJES DE VALIDACIÓN EN ESPAÑOL
|--------------------------------------------------------------------------
|
| POR QUÉ EXISTE ESTE FICHERO. La aplicación corre con `APP_LOCALE=es` y
| Laravel no trae traducciones: cuando no encuentra el texto devuelve la
| CLAVE. Al usuario del CRM le llegaba literalmente «validation.min.numeric»
| donde tenía que leer qué había escrito mal, y eso no es un mensaje feo —es
| un formulario que no se puede corregir porque no dice qué le pasa.
|
| Se traduce lo que este CRM usa de verdad. Lo que quede en inglés es una
| regla que ninguna pantalla aplica todavía; el día que se use, se traduce.
|
*/
return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'Tienes que aceptar :attribute.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => ':attribute tiene que ser posterior a :date.',
    'after_or_equal' => ':attribute no puede ser anterior a :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'any_of' => 'The :attribute field is invalid.',
    'array' => ':attribute tiene que ser una lista.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'before' => ':attribute tiene que ser anterior a :date.',
    'before_or_equal' => ':attribute no puede ser posterior a :date.',
    'between' => [
        'array' => ':attribute tiene que llevar entre :min y :max elementos.',
        'file' => ':attribute tiene que pesar entre :min y :max kilobytes.',
        'numeric' => ':attribute tiene que estar entre :min y :max.',
        'string' => ':attribute tiene que tener entre :min y :max caracteres.',
    ],
    'boolean' => ':attribute tiene que ser sí o no.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => ':attribute no es una fecha válida.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => ':attribute no tiene el formato :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => ':attribute y :other tienen que ser distintos.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'doesnt_contain' => 'The :attribute field must not contain any of the following: :values.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => ':attribute no es un correo válido.',
    'encoding' => 'The :attribute field must be encoded in :encoding.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'El :attribute que elegiste no existe.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => ':attribute no puede quedar vacío.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => ':attribute tiene que ser una imagen.',
    'in' => 'El valor de :attribute no es válido.',
    'in_array' => 'The :attribute field must exist in :other.',
    'in_array_keys' => 'The :attribute field must contain at least one of the following keys: :values.',
    'integer' => ':attribute tiene que ser un número entero.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => ':attribute tiene que ser JSON válido.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => ':attribute no puede llevar más de :max elementos.',
        'file' => ':attribute no puede pesar más de :max kilobytes.',
        'numeric' => ':attribute no puede ser mayor que :max.',
        'string' => ':attribute no puede tener más de :max caracteres.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => ':attribute tiene que llevar al menos :min elementos.',
        'file' => ':attribute tiene que pesar al menos :min kilobytes.',
        'numeric' => ':attribute no puede ser menor que :min.',
        'string' => ':attribute tiene que tener al menos :min caracteres.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'Ese valor de :attribute no se admite.',
    'not_regex' => 'The :attribute field format is invalid.',
    'numeric' => ':attribute tiene que ser un número.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => 'Falta :attribute.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => ':attribute no se puede enviar.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => ':attribute es obligatorio.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => ':attribute tiene que coincidir con :other.',
    'size' => [
        'array' => ':attribute tiene que llevar :size elementos.',
        'file' => ':attribute tiene que pesar :size kilobytes.',
        'numeric' => ':attribute tiene que ser :size.',
        'string' => ':attribute tiene que tener :size caracteres.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => ':attribute tiene que ser texto.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'Ya existe otro registro con ese :attribute.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => 'The :attribute field must be uppercase.',
    'url' => ':attribute no es una dirección válida.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => ':attribute no es un identificador válido.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    /*
    |----------------------------------------------------------------------
    | CÓMO SE LLAMA CADA CAMPO
    |----------------------------------------------------------------------
    |
    | Sin esto el mensaje dice «amount no puede ser menor que 1», con el
    | nombre de la columna. Quien está en el mostrador no sabe qué es
    | `amount`; sabe qué es «el valor al mes».
    |
    */
    'attributes' => [
        'amount' => 'El valor',
        'payer' => 'Quién lo asume',
        'trainer_id' => 'El entrenador',
        'member_id' => 'El cliente',
        'period' => 'El mes',
        'due_at' => 'La fecha límite',
        'method' => 'El medio de pago',
        'notes' => 'La nota',
        'note' => 'La nota',
        'name' => 'El nombre',
        'full_name' => 'El nombre',
        'email' => 'El correo',
        'password' => 'La contraseña',
        'phone' => 'El teléfono',
        'document' => 'El documento',
        'document_number' => 'El documento',
        'birth_date' => 'La fecha de nacimiento',
        'birthDate' => 'La fecha de nacimiento',
        'price' => 'El precio',
        'duration_days' => 'La duración en días',
        'plan_id' => 'El plan',
        'user_id' => 'El socio',
        'starts_on' => 'La fecha de inicio',
        'ends_on' => 'La fecha de fin',
        'position' => 'El cargo',
        'reason' => 'El motivo',
        'kind' => 'El tipo',
        'status' => 'El estado',
        'access_days' => 'Los días permitidos',
        'access_windows' => 'Las franjas horarias',
        'entry_credits' => 'Las entradas incluidas',
    ],

];
