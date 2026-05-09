import 'food_item.dart';

enum MealType {
  breakfast,
  lunch,
  dinner,
  snacks;

  String get displayName => switch (this) {
        MealType.breakfast => 'Desayuno',
        MealType.lunch => 'Almuerzo',
        MealType.dinner => 'Cena',
        MealType.snacks => 'Meriendas',
      };
}

class MealEntry {
  final String id;
  final MealType mealType;
  final FoodItem food;
  final double quantity;

  const MealEntry({
    required this.id,
    required this.mealType,
    required this.food,
    required this.quantity,
  });

  double get calories => food.scaledCalories(quantity);
  double get protein => food.scaledProtein(quantity);
  double get carbs => food.scaledCarbs(quantity);
  double get fat => food.scaledFat(quantity);

  String get quantityLabel =>
      food.unit == 'g' ? '${quantity.toStringAsFixed(0)}g' : '${quantity.toStringAsFixed(1)} ${food.unit}';

  Map<String, dynamic> toJson() => {
        'id': id,
        'mealType': mealType.name,
        'food': food.toJson(),
        'quantity': quantity,
      };

  factory MealEntry.fromJson(Map<String, dynamic> json) => MealEntry(
        id: json['id'] as String,
        mealType: MealType.values.firstWhere(
          (m) => m.name == json['mealType'],
          orElse: () => MealType.breakfast,
        ),
        food: FoodItem.fromJson(json['food'] as Map<String, dynamic>),
        quantity: (json['quantity'] as num).toDouble(),
      );
}
