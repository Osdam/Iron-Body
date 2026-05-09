class FoodItem {
  final String id;
  final String name;
  final double calories;
  final double protein;
  final double carbs;
  final double fat;
  final double baseQuantity;
  final String unit;
  final bool isCustom;

  const FoodItem({
    required this.id,
    required this.name,
    required this.calories,
    required this.protein,
    required this.carbs,
    required this.fat,
    this.baseQuantity = 100,
    this.unit = 'g',
    this.isCustom = false,
  });

  double scaledCalories(double qty) => calories * qty / baseQuantity;
  double scaledProtein(double qty) => protein * qty / baseQuantity;
  double scaledCarbs(double qty) => carbs * qty / baseQuantity;
  double scaledFat(double qty) => fat * qty / baseQuantity;

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'calories': calories,
        'protein': protein,
        'carbs': carbs,
        'fat': fat,
        'baseQuantity': baseQuantity,
        'unit': unit,
        'isCustom': isCustom,
      };

  factory FoodItem.fromJson(Map<String, dynamic> json) => FoodItem(
        id: json['id'] as String,
        name: json['name'] as String,
        calories: (json['calories'] as num).toDouble(),
        protein: (json['protein'] as num).toDouble(),
        carbs: (json['carbs'] as num).toDouble(),
        fat: (json['fat'] as num).toDouble(),
        baseQuantity: (json['baseQuantity'] as num?)?.toDouble() ?? 100,
        unit: json['unit'] as String? ?? 'g',
        isCustom: json['isCustom'] as bool? ?? false,
      );
}
