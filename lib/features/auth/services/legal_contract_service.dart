import '../models/guardian_info.dart';
import '../models/legal_consent.dart';

/// Textos legales BASE y editables. ⚠️ NO son textos jurídicamente válidos:
/// deben ser revisados y aprobados por el abogado del gimnasio antes de
/// producción. Aquí solo sirven como contenido placeholder estructurado para
/// que el usuario pueda leer algo coherente durante el flujo.
class LegalTexts {
  LegalTexts._();

  static const String contractTitle = 'Contrato de prestación de servicios';
  static const String contractBody = '''
Este documento describe, en términos generales, la relación entre el usuario y
el gimnasio para el uso de instalaciones, clases dirigidas, asesoría de
entrenamiento y servicios asociados.

⚠️ Borrador editable — debe ser revisado por un abogado:
• Objeto del contrato y servicios incluidos.
• Vigencia, renovación y cancelación.
• Obligaciones del usuario (uso adecuado de equipos, normas de convivencia,
  veracidad de la información, estado de salud apto para actividad física).
• Obligaciones del gimnasio (disponibilidad de instalaciones, personal
  capacitado, condiciones de seguridad).
• Política de pagos, mora y suspensión del servicio.
• Causales de terminación.
''';

  static const String termsTitle = 'Términos y condiciones';
  static const String termsBody = '''
Condiciones generales de uso de la aplicación y de los servicios del gimnasio.

⚠️ Borrador editable — debe ser revisado por un abogado:
• Aceptación de las condiciones al registrarse y usar la app.
• Reglas de uso de la cuenta y responsabilidad sobre las credenciales.
• Limitaciones de responsabilidad y disponibilidad del servicio.
• Modificaciones a los términos y forma de notificarlas.
• Legislación aplicable y mecanismos de solución de controversias.
''';

  static const String dataTitle = 'Tratamiento de datos personales';
  static const String dataBody = '''
Información sobre cómo se recolectan, usan, almacenan y protegen tus datos
personales, incluidos datos sensibles como imágenes de documento y datos
biométricos faciales.

⚠️ Borrador editable — debe ser revisado por un abogado / oficial de
protección de datos:
• Responsable del tratamiento y datos de contacto.
• Finalidades: verificación de identidad, control de acceso, gestión de
  membresía, comunicación con el usuario.
• Base legal del tratamiento y carácter facultativo de ciertos datos.
• Tiempo de conservación y medidas de seguridad (cifrado en reposo y en
  tránsito, acceso restringido).
• Derechos del titular (conocer, actualizar, rectificar, suprimir, revocar la
  autorización) y canales para ejercerlos.
• Transferencias o encargos a terceros, si aplica.
''';

  static const String riskTitle = 'Aceptación de riesgos de actividad física';
  static const String riskBody = '''
Declaración sobre los riesgos inherentes a la práctica de ejercicio físico y la
recomendación de contar con valoración médica previa.

⚠️ Borrador editable — debe ser revisado por un abogado:
• Reconocimiento de que la actividad física conlleva riesgos.
• Declaración de aptitud física o existencia de condiciones a informar.
• Alcance y límites de la exoneración de responsabilidad permitidos por la ley.
• Compromiso de seguir las indicaciones del personal del gimnasio.
''';

  static const String guardianTitle = 'Autorización del responsable legal';
  static const String guardianBody = '''
Cuando el usuario es menor de edad, el padre, madre o representante legal debe
autorizar expresamente el registro, el ingreso y uso de las instalaciones, la
participación en clases, el tratamiento de los datos personales del menor
(incluidos documento y datos biométricos) y la adquisición de la membresía.

⚠️ Borrador editable — debe ser revisado por un abogado:
• Identificación del responsable y del menor.
• Alcance de la autorización y responsabilidad asumida.
• Vigencia y revocación de la autorización.
''';
}

/// Capa de persistencia del consentimiento legal. Hoy es un mock: cuando exista
/// el backend, reemplazar [submitConsent] por un POST autenticado. El servidor
/// debe almacenar de forma íntegra y auditable la aceptación (timestamp, IP/
/// dispositivo si aplica, versión de los textos aceptados).
class LegalContractService {
  LegalContractService._();
  static final LegalContractService instance = LegalContractService._();

  Future<void> submitConsent(
    LegalConsent consent, {
    required String userId,
    GuardianInfo? guardian,
  }) async {
    // TODO(backend): POST /legal/consent
    //   fields: { userId, acceptedAt, contractVersion, ...flags }
    //   if guardian != null -> { guardianName, guardianDocument, guardianPhone,
    //                            guardianEmail, guardianRelationship }
    //   files:  { 'signature': <consent.signature.filePath> }
    //   headers: { 'Authorization': 'Bearer <session-token>' }
    await Future<void>.delayed(const Duration(milliseconds: 250));
  }
}
