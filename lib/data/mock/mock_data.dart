import 'package:flutter/material.dart';
import '../models/user_model.dart';
import '../models/membership_plan_model.dart';
import '../models/payment_model.dart';
import '../models/exercise_model.dart';
import '../models/workout_model.dart';
import '../models/class_session_model.dart';
import '../models/product_model.dart';
import '../models/notification_model.dart';
import '../models/ai_message_model.dart';

// ──────────────────────────────────────────────
// USUARIO
// ──────────────────────────────────────────────
final mockUser = UserModel(
  id: 'u1',
  fullName: 'Alejandro García',
  email: 'usuario@ironbody.com',
  document: '123456789',
  phone: '3001234567',
  goal: 'Hipertrofia muscular',
  weight: 78.5,
  height: 175,
  planName: 'Plan Mensual',
  membershipExpiry: DateTime.now().add(const Duration(days: 18)),
  streak: 12,
  workoutsCompleted: 47,
);

// ──────────────────────────────────────────────
// PLANES DE MEMBRESÍA
// ──────────────────────────────────────────────
final mockPlans = <MembershipPlanModel>[
  MembershipPlanModel(
    id: 'p1',
    name: 'Mensual',
    period: '1 mes',
    months: 1,
    price: 120000,
    benefits: [
      'Acceso ilimitado al gimnasio',
      'Vestuario y duchas',
      'Evaluación física inicial',
      'Seguimiento básico de progreso',
    ],
  ),
  MembershipPlanModel(
    id: 'p2',
    name: 'Trimestral',
    period: '3 meses',
    months: 3,
    price: 320000,
    originalPrice: 360000,
    benefits: [
      'Todo lo del plan mensual',
      '1 clase grupal por semana',
      'Rutina personalizada',
      'Acceso a IRON IA básico',
      'Ahorra \$40.000',
    ],
    isRecommended: true,
    badge: 'Más popular',
  ),
  MembershipPlanModel(
    id: 'p3',
    name: 'Semestral',
    period: '6 meses',
    months: 6,
    price: 580000,
    originalPrice: 720000,
    benefits: [
      'Todo lo del plan trimestral',
      'Clases ilimitadas',
      'IRON IA completo',
      'Nutrición básica',
      'Ahorra \$140.000',
    ],
    badge: 'Mejor valor',
  ),
  MembershipPlanModel(
    id: 'p4',
    name: 'Anual',
    period: '12 meses',
    months: 12,
    price: 990000,
    originalPrice: 1440000,
    benefits: [
      'Todo incluido',
      'IRON IA premium ilimitado',
      'Nutrición personalizada',
      'Sesiones con entrenador',
      'Acceso a tienda con descuento',
      'Ahorra \$450.000',
    ],
    badge: 'Elite',
  ),
];

// ──────────────────────────────────────────────
// HISTORIAL DE PAGOS
// ──────────────────────────────────────────────
final mockPayments = <PaymentModel>[
  PaymentModel(
    id: 'pay1',
    planName: 'Plan Mensual',
    amount: 120000,
    date: DateTime.now().subtract(const Duration(days: 30)),
    status: PaymentStatus.approved,
    reference: 'WP-20240402-001',
  ),
  PaymentModel(
    id: 'pay2',
    planName: 'Plan Mensual',
    amount: 120000,
    date: DateTime.now().subtract(const Duration(days: 60)),
    status: PaymentStatus.approved,
    reference: 'WP-20240302-002',
  ),
  PaymentModel(
    id: 'pay3',
    planName: 'Plan Mensual',
    amount: 120000,
    date: DateTime.now().subtract(const Duration(days: 92)),
    status: PaymentStatus.rejected,
    reference: 'WP-20240201-003',
  ),
];

// ──────────────────────────────────────────────
// EJERCICIOS
// ──────────────────────────────────────────────
final mockExercises = <ExerciseModel>[
  ExerciseModel(
    id: 'e1',
    name: 'Press de Banca',
    muscleGroup: 'Pecho',
    equipment: 'Barra',
    difficulty: 'Intermedio',
    description: 'Ejercicio compuesto para desarrollo del pecho, hombros y tríceps.',
    steps: [
      'Acuéstate en el banco con los pies apoyados en el suelo.',
      'Agarra la barra con agarre ligeramente más ancho que los hombros.',
      'Baja la barra controlando hasta el pecho.',
      'Empuja hacia arriba extendiendo los codos por completo.',
    ],
    commonMistakes: ['Arquear excesivamente la espalda', 'Rebotar en el pecho'],
    secondaryMuscles: ['Hombros', 'Tríceps'],
    suggestedSets: 4,
    suggestedReps: '8-10',
  ),
  ExerciseModel(
    id: 'e2',
    name: 'Sentadilla',
    muscleGroup: 'Piernas',
    equipment: 'Barra',
    difficulty: 'Intermedio',
    description: 'El rey de los ejercicios para piernas. Trabaja cuádriceps, glúteos e isquiotibiales.',
    steps: [
      'Coloca la barra en la parte alta de la espalda.',
      'Separa los pies al ancho de los hombros.',
      'Baja flexionando rodillas y caderas manteniendo la espalda neutra.',
      'Sube empujando el suelo con los talones.',
    ],
    commonMistakes: ['Rodillas hacia adentro', 'Talones levantados'],
    secondaryMuscles: ['Core', 'Lumbares'],
    suggestedSets: 4,
    suggestedReps: '6-10',
  ),
  ExerciseModel(
    id: 'e3',
    name: 'Peso Muerto',
    muscleGroup: 'Espalda',
    equipment: 'Barra',
    difficulty: 'Avanzado',
    description: 'Ejercicio compuesto total. Trabaja toda la cadena posterior.',
    steps: [
      'Para frente a la barra con pies al ancho de la cadera.',
      'Agarra la barra justo fuera de las piernas.',
      'Mantén la espalda plana y el core activo.',
      'Levanta empujando el suelo y extendiendo caderas.',
    ],
    commonMistakes: ['Espalda redondeada', 'Barra lejos del cuerpo'],
    secondaryMuscles: ['Glúteos', 'Cuádriceps', 'Core'],
    suggestedSets: 4,
    suggestedReps: '5-8',
  ),
  ExerciseModel(
    id: 'e4',
    name: 'Dominadas',
    muscleGroup: 'Espalda',
    equipment: 'Peso corporal',
    difficulty: 'Intermedio',
    description: 'Excelente para desarrollar el ancho de espalda y bíceps.',
    steps: [
      'Agarra la barra con agarre prono más ancho que los hombros.',
      'Cuelga con brazos extendidos.',
      'Tira hacia arriba hasta que la barbilla supere la barra.',
      'Baja controlado hasta extender los brazos.',
    ],
    commonMistakes: ['Usar impulso', 'No extender completamente'],
    secondaryMuscles: ['Bíceps', 'Core'],
    suggestedSets: 3,
    suggestedReps: '6-10',
  ),
  ExerciseModel(
    id: 'e5',
    name: 'Press Militar',
    muscleGroup: 'Hombros',
    equipment: 'Barra',
    difficulty: 'Intermedio',
    description: 'Ejercicio fundamental para construir hombros fuertes y anchos.',
    steps: [
      'De pie o sentado, sostén la barra a la altura del pecho.',
      'Presiona hacia arriba hasta extender los brazos.',
      'Baja controlado hasta el nivel del mentón.',
    ],
    commonMistakes: ['Arquear la espalda', 'Codos muy hacia adelante'],
    secondaryMuscles: ['Tríceps', 'Core'],
    suggestedSets: 4,
    suggestedReps: '8-12',
  ),
  ExerciseModel(
    id: 'e6',
    name: 'Curl con Mancuernas',
    muscleGroup: 'Brazos',
    equipment: 'Mancuernas',
    difficulty: 'Principiante',
    description: 'Ejercicio de aislamiento para desarrollo del bíceps.',
    steps: [
      'De pie con mancuernas a los lados.',
      'Flexiona los codos llevando las mancuernas hacia los hombros.',
      'Aprieta el bíceps en la parte superior.',
      'Baja lentamente a la posición inicial.',
    ],
    commonMistakes: ['Usar el cuerpo para impulsar', 'No controlar la bajada'],
    secondaryMuscles: ['Braquial', 'Antebrazo'],
    suggestedSets: 3,
    suggestedReps: '10-15',
  ),
  ExerciseModel(
    id: 'e7',
    name: 'Plancha',
    muscleGroup: 'Core',
    equipment: 'Peso corporal',
    difficulty: 'Principiante',
    description: 'Ejercicio isométrico fundamental para fortalecer el core.',
    steps: [
      'Apóyate sobre los antebrazos y puntas de los pies.',
      'Mantén el cuerpo en línea recta.',
      'Activa el abdomen y glúteos.',
      'Mantén la posición el tiempo indicado.',
    ],
    commonMistakes: ['Cadera arriba o abajo', 'No respirar'],
    secondaryMuscles: ['Hombros', 'Glúteos'],
    suggestedSets: 3,
    suggestedReps: '30-60 seg',
  ),
  ExerciseModel(
    id: 'e8',
    name: 'Burpees',
    muscleGroup: 'Cardio',
    equipment: 'Peso corporal',
    difficulty: 'Avanzado',
    description: 'Ejercicio de cuerpo completo de alta intensidad.',
    steps: [
      'De pie, desciende a posición de squat.',
      'Lleva los pies hacia atrás a posición de plancha.',
      'Haz una flexión.',
      'Regresa los pies y salta explosivamente.',
    ],
    commonMistakes: ['Perder la posición de plancha', 'Sin salto'],
    secondaryMuscles: ['Todo el cuerpo'],
    suggestedSets: 3,
    suggestedReps: '10-15',
  ),
];

// ──────────────────────────────────────────────
// RUTINAS
// ──────────────────────────────────────────────
List<WorkoutModel> get mockWorkouts => [
      WorkoutModel(
        id: 'w1',
        name: 'Pecho y Tríceps',
        muscleGroup: 'Pecho · Tríceps',
        level: 'Intermedio',
        estimatedMinutes: 55,
        isAssigned: true,
        exercises: [
          WorkoutExercise(exercise: mockExercises[0], sets: 4, reps: '10', weight: 60),
          WorkoutExercise(exercise: mockExercises[4], sets: 3, reps: '12', weight: 40),
          WorkoutExercise(exercise: mockExercises[5], sets: 3, reps: '12', weight: 14),
        ],
      ),
      WorkoutModel(
        id: 'w2',
        name: 'Espalda y Bíceps',
        muscleGroup: 'Espalda · Bíceps',
        level: 'Intermedio',
        estimatedMinutes: 60,
        isAssigned: true,
        exercises: [
          WorkoutExercise(exercise: mockExercises[2], sets: 4, reps: '6', weight: 100),
          WorkoutExercise(exercise: mockExercises[3], sets: 3, reps: '8', weight: 0),
          WorkoutExercise(exercise: mockExercises[5], sets: 3, reps: '12', weight: 12),
        ],
      ),
      WorkoutModel(
        id: 'w3',
        name: 'Pierna Completa',
        muscleGroup: 'Cuádriceps · Glúteos · Isquiotibiales',
        level: 'Avanzado',
        estimatedMinutes: 70,
        exercises: [
          WorkoutExercise(exercise: mockExercises[1], sets: 5, reps: '8', weight: 80),
          WorkoutExercise(exercise: mockExercises[2], sets: 4, reps: '6', weight: 100),
        ],
      ),
      WorkoutModel(
        id: 'w4',
        name: 'Full Body',
        muscleGroup: 'Cuerpo completo',
        level: 'Principiante',
        estimatedMinutes: 45,
        exercises: [
          WorkoutExercise(exercise: mockExercises[1], sets: 3, reps: '12', weight: 40),
          WorkoutExercise(exercise: mockExercises[0], sets: 3, reps: '12', weight: 40),
          WorkoutExercise(exercise: mockExercises[6], sets: 3, reps: '45 seg', weight: 0),
        ],
      ),
      WorkoutModel(
        id: 'w5',
        name: 'Cardio Funcional',
        muscleGroup: 'Cardio · Core',
        level: 'Intermedio',
        estimatedMinutes: 35,
        exercises: [
          WorkoutExercise(exercise: mockExercises[7], sets: 4, reps: '15', weight: 0),
          WorkoutExercise(exercise: mockExercises[6], sets: 3, reps: '60 seg', weight: 0),
        ],
      ),
    ];

// ──────────────────────────────────────────────
// CLASES
// ──────────────────────────────────────────────
List<ClassSessionModel> get mockClasses {
  final now = DateTime.now();
  return [
    ClassSessionModel(
      id: 'c1',
      name: 'Spinning',
      type: 'Cardio',
      instructor: 'Carlos Ruiz',
      dateTime: DateTime(now.year, now.month, now.day, 7, 0),
      durationMinutes: 45,
      totalSpots: 20,
      bookedSpots: 18,
      description: 'Clase de ciclismo indoor de alta intensidad. Mejora resistencia cardiovascular y quema calorías al ritmo de la música.',
    ),
    ClassSessionModel(
      id: 'c2',
      name: 'Funcional',
      type: 'Fuerza',
      instructor: 'Daniela Torres',
      dateTime: DateTime(now.year, now.month, now.day, 9, 30),
      durationMinutes: 50,
      totalSpots: 15,
      bookedSpots: 8,
      isReserved: true,
      description: 'Entrenamiento con peso corporal y herramientas funcionales. Mejora fuerza, coordinación y movilidad articular.',
    ),
    ClassSessionModel(
      id: 'c3',
      name: 'Cross Training',
      type: 'CrossFit',
      instructor: 'Andrés Mora',
      dateTime: DateTime(now.year, now.month, now.day, 12, 0),
      durationMinutes: 60,
      totalSpots: 12,
      bookedSpots: 12,
      description: 'Entrenamiento de alta intensidad combinando levantamiento olímpico, gimnasia y cardio. Apto para niveles intermedios.',
    ),
    ClassSessionModel(
      id: 'c4',
      name: 'HIIT',
      type: 'Cardio',
      instructor: 'Laura Pinzón',
      dateTime: DateTime(now.year, now.month, now.day + 1, 6, 30),
      durationMinutes: 40,
      totalSpots: 20,
      bookedSpots: 5,
      description: 'Intervalos de alta intensidad con períodos de recuperación activa. Maximiza la quema de grasa y mejora el acondicionamiento.',
    ),
    ClassSessionModel(
      id: 'c5',
      name: 'Yoga / Movilidad',
      type: 'Flexibilidad',
      instructor: 'Sofía Herrera',
      dateTime: DateTime(now.year, now.month, now.day + 1, 8, 0),
      durationMinutes: 60,
      totalSpots: 16,
      bookedSpots: 10,
      description: 'Sesión de yoga y movilidad para mejorar la flexibilidad, reducir tensiones y recuperar el cuerpo tras entrenamientos intensos.',
    ),
    ClassSessionModel(
      id: 'c6',
      name: 'Abdomen Express',
      type: 'Core',
      instructor: 'Carlos Ruiz',
      dateTime: DateTime(now.year, now.month, now.day + 2, 7, 0),
      durationMinutes: 30,
      totalSpots: 20,
      bookedSpots: 3,
      description: 'Rutina intensiva de 30 minutos enfocada en el core: abdomen, oblicuos y zona lumbar. Sin equipamiento, solo peso corporal.',
    ),
  ];
}

// ──────────────────────────────────────────────
// PRODUCTOS
// ──────────────────────────────────────────────
final mockProducts = <ProductModel>[
  ProductModel(id: 'pr1', name: 'Proteína Whey Chocolate', category: 'Suplementos', price: 180000, stock: 12, iconData: Icons.science_rounded, description: 'Proteína de suero de leche de alta calidad. 25g de proteína por porción. Sabor chocolate belga.'),
  ProductModel(id: 'pr2', name: 'Creatina Monohidrato', category: 'Suplementos', price: 95000, stock: 8, iconData: Icons.biotech_rounded, description: 'Creatina pura de alta pureza. Aumenta fuerza y rendimiento. 300g por frasco.'),
  ProductModel(id: 'pr3', name: 'Pre-entreno IRON', category: 'Suplementos', price: 120000, stock: 5, iconData: Icons.bolt_rounded, description: 'Fórmula premium para maximizar el rendimiento. Cafeína, beta-alanina y citrulina.'),
  ProductModel(id: 'pr4', name: 'Shaker Iron Body', category: 'Accesorios', price: 35000, stock: 20, iconData: Icons.local_bar_rounded, description: 'Shaker oficial Iron Body. 700ml, libre de BPA, tapa hermética con mezclador.'),
  ProductModel(id: 'pr5', name: 'Guantes de Entrenamiento', category: 'Accesorios', price: 45000, stock: 15, iconData: Icons.sports_mma_rounded, description: 'Guantes premium con soporte de muñeca. Agarre antideslizante.'),
  ProductModel(id: 'pr6', name: 'Agua Iron Body 600ml', category: 'Bebidas', price: 4000, stock: 50, iconData: Icons.water_drop_rounded, description: 'Agua purificada oficial Iron Body.'),
  ProductModel(id: 'pr7', name: 'Bebida Energizante', category: 'Bebidas', price: 8000, stock: 30, iconData: Icons.local_drink_rounded, description: 'Bebida energizante con electrolitos y vitaminas. Sin azúcar.'),
  ProductModel(id: 'pr8', name: 'Barra de Proteína', category: 'Snacks', price: 12000, stock: 3, iconData: Icons.lunch_dining_rounded, description: 'Barra proteica con 20g de proteína y bajo en azúcar.'),
  ProductModel(id: 'pr9', name: 'Toalla Deportiva', category: 'Accesorios', price: 28000, stock: 10, iconData: Icons.dry_cleaning_rounded, description: 'Toalla microfibra de alta absorción. 50x100cm con bolsa incluida.'),
  ProductModel(id: 'pr10', name: 'BCAA Tropical', category: 'Suplementos', price: 85000, stock: 7, iconData: Icons.science_rounded, description: 'Aminoácidos de cadena ramificada. Sabor tropical. Reduce el catabolismo muscular.'),
];

// ──────────────────────────────────────────────
// NOTIFICACIONES
// ──────────────────────────────────────────────
List<NotificationModel> get mockNotifications => [
      NotificationModel(
        id: 'n1',
        title: 'Membresía próxima a vencer',
        body: 'Tu membresía vence en 18 días. Renueva ahora para no perder tu acceso.',
        type: NotificationType.payment,
        createdAt: DateTime.now().subtract(const Duration(hours: 2)),
      ),
      NotificationModel(
        id: 'n2',
        title: 'Clase reservada',
        body: 'Reservaste Funcional con Daniela Torres hoy a las 9:30 AM.',
        type: NotificationType.classes,
        createdAt: DateTime.now().subtract(const Duration(hours: 5)),
        isRead: true,
      ),
      NotificationModel(
        id: 'n3',
        title: 'IRON IA recomienda',
        body: 'Esta semana entrenaste 3 veces. ¡Agrega un día de pierna para balancear!',
        type: NotificationType.trainer,
        createdAt: DateTime.now().subtract(const Duration(days: 1)),
      ),
      NotificationModel(
        id: 'n4',
        title: 'Pago aprobado',
        body: 'Tu pago de \$120.000 fue aprobado. Plan Mensual activado.',
        type: NotificationType.payment,
        createdAt: DateTime.now().subtract(const Duration(days: 30)),
        isRead: true,
      ),
      NotificationModel(
        id: 'n5',
        title: 'Nueva rutina asignada',
        body: 'Tu entrenador asignó una nueva rutina: Pecho y Tríceps Avanzado.',
        type: NotificationType.trainer,
        createdAt: DateTime.now().subtract(const Duration(days: 2)),
        isRead: true,
      ),
      NotificationModel(
        id: 'n6',
        title: 'Promoción especial',
        body: '¡Solo este mes! Plan Trimestral con 15% OFF. Código: IRON15',
        type: NotificationType.promo,
        createdAt: DateTime.now().subtract(const Duration(days: 3)),
      ),
    ];

// ──────────────────────────────────────────────
// MENSAJES IA INICIALES
// ──────────────────────────────────────────────
List<AiMessage> get mockAiWelcome => [
      AiMessage(
        id: 'ai0',
        content:
            'Hola Alejandro. Soy IRON, tu asistente de entrenamiento con IA.\n\nEstoy aquí para ayudarte con rutinas, técnicas, nutrición y todo lo relacionado con tu progreso en el gimnasio.\n\n¿En qué puedo ayudarte hoy?',
        isUser: false,
        timestamp: DateTime.now().subtract(const Duration(minutes: 1)),
      ),
    ];

// ──────────────────────────────────────────────
// SESIÓN DE USUARIO
// ──────────────────────────────────────────────
class AppSession {
  static UserModel? _currentUser;

  static UserModel? get currentUser => _currentUser;
  static bool get isLoggedIn => _currentUser != null;

  static void login(UserModel user) => _currentUser = user;
  static void logout() => _currentUser = null;

  static bool validateCredentials(String emailOrDoc, String password) {
    return (emailOrDoc == 'usuario@ironbody.com' ||
            emailOrDoc == '123456789') &&
        password == 'admin123';
  }
}
