class NutritionGoals {
  final double calories;
  final double protein;
  final double carbs;
  final double fat;

  const NutritionGoals({
    required this.calories,
    required this.protein,
    required this.carbs,
    required this.fat,
  });

  static const NutritionGoals defaults = NutritionGoals(
    calories: 2200,
    protein: 150,
    carbs: 250,
    fat: 70,
  );

  NutritionGoals copyWith({
    double? calories,
    double? protein,
    double? carbs,
    double? fat,
  }) =>
      NutritionGoals(
        calories: calories ?? this.calories,
        protein: protein ?? this.protein,
        carbs: carbs ?? this.carbs,
        fat: fat ?? this.fat,
      );

  Map<String, dynamic> toJson() => {
        'calories': calories,
        'protein': protein,
        'carbs': carbs,
        'fat': fat,
      };

  factory NutritionGoals.fromJson(Map<String, dynamic> json) => NutritionGoals(
        calories: (json['calories'] as num).toDouble(),
        protein: (json['protein'] as num).toDouble(),
        carbs: (json['carbs'] as num).toDouble(),
        fat: (json['fat'] as num).toDouble(),
      );
}
