class NutritionStreak {
  final int current;
  final List<String> completedDates;

  const NutritionStreak({required this.current, required this.completedDates});

  static const NutritionStreak empty = NutritionStreak(current: 0, completedDates: []);

  Map<String, dynamic> toJson() => {
        'current': current,
        'completedDates': completedDates,
      };

  factory NutritionStreak.fromJson(Map<String, dynamic> json) => NutritionStreak(
        current: json['current'] as int? ?? 0,
        completedDates: (json['completedDates'] as List<dynamic>?)
                ?.map((e) => e as String)
                .toList() ??
            [],
      );
}
