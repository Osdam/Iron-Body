import 'meal_entry.dart';

class DailyNutritionLog {
  final String date;
  final List<MealEntry> entries;

  const DailyNutritionLog({required this.date, required this.entries});

  double get totalCalories => entries.fold(0.0, (s, e) => s + e.calories);
  double get totalProtein => entries.fold(0.0, (s, e) => s + e.protein);
  double get totalCarbs => entries.fold(0.0, (s, e) => s + e.carbs);
  double get totalFat => entries.fold(0.0, (s, e) => s + e.fat);

  List<MealEntry> entriesFor(MealType meal) =>
      entries.where((e) => e.mealType == meal).toList();

  double caloriesFor(MealType meal) =>
      entriesFor(meal).fold(0.0, (s, e) => s + e.calories);

  DailyNutritionLog addEntry(MealEntry entry) =>
      DailyNutritionLog(date: date, entries: [...entries, entry]);

  DailyNutritionLog removeEntry(String entryId) => DailyNutritionLog(
      date: date, entries: entries.where((e) => e.id != entryId).toList());

  Map<String, dynamic> toJson() => {
        'date': date,
        'entries': entries.map((e) => e.toJson()).toList(),
      };

  factory DailyNutritionLog.fromJson(Map<String, dynamic> json) =>
      DailyNutritionLog(
        date: json['date'] as String,
        entries: (json['entries'] as List<dynamic>)
            .map((e) => MealEntry.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}
