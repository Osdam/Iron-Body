import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import '../../../data/models/food_item.dart';
import '../../../data/models/meal_entry.dart';
import '../../../data/models/daily_nutrition_log.dart';
import '../../../data/models/nutrition_goals.dart';
import '../../../data/models/nutrition_streak.dart';

class NutritionService {
  NutritionService._();
  static final NutritionService instance = NutritionService._();

  static const _goalsKey = 'nutrition_goals';
  static const _customFoodsKey = 'nutrition_custom_foods';
  static const _streakKey = 'nutrition_streak';
  static String _logKey(String date) => 'nutrition_log_$date';

  SharedPreferences? _prefs;
  bool _baseLoaded = false;

  DailyNutritionLog? _todayLog;
  NutritionGoals _goals = NutritionGoals.defaults;
  List<FoodItem> _customFoods = [];
  NutritionStreak _streak = NutritionStreak.empty;

  String get todayDate => DateTime.now().toIso8601String().substring(0, 10);

  DailyNutritionLog get todayLog =>
      _todayLog ?? DailyNutritionLog(date: todayDate, entries: []);
  NutritionGoals get goals => _goals;
  List<FoodItem> get customFoods => List.unmodifiable(_customFoods);
  NutritionStreak get streak => _streak;

  Future<void> init() async {
    _prefs ??= await SharedPreferences.getInstance();
    if (!_baseLoaded) {
      _loadGoals();
      _loadCustomFoods();
      _loadStreak();
      _baseLoaded = true;
    }
    await _loadTodayLog();
  }

  void _loadGoals() {
    final raw = _prefs!.getString(_goalsKey);
    if (raw == null) return;
    try {
      _goals = NutritionGoals.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {}
  }

  Future<void> _loadTodayLog() async {
    final raw = _prefs!.getString(_logKey(todayDate));
    if (raw == null) {
      _todayLog = DailyNutritionLog(date: todayDate, entries: []);
      return;
    }
    try {
      _todayLog =
          DailyNutritionLog.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      _todayLog = DailyNutritionLog(date: todayDate, entries: []);
    }
  }

  void _loadCustomFoods() {
    final raw = _prefs!.getString(_customFoodsKey);
    if (raw == null) return;
    try {
      _customFoods = (jsonDecode(raw) as List<dynamic>)
          .map((e) => FoodItem.fromJson(e as Map<String, dynamic>))
          .toList();
    } catch (_) {}
  }

  void _loadStreak() {
    final raw = _prefs!.getString(_streakKey);
    if (raw == null) return;
    try {
      _streak = NutritionStreak.fromJson(
          jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {}
  }

  Future<void> addEntry(MealEntry entry) async {
    _todayLog = todayLog.addEntry(entry);
    await _saveTodayLog();
  }

  Future<void> removeEntry(String entryId) async {
    _todayLog = todayLog.removeEntry(entryId);
    await _saveTodayLog();
  }

  Future<void> saveGoals(NutritionGoals goals) async {
    _goals = goals;
    await _prefs!.setString(_goalsKey, jsonEncode(goals.toJson()));
    _checkAndUpdateStreak();
  }

  Future<void> addCustomFood(FoodItem food) async {
    _customFoods = [..._customFoods, food];
    await _prefs!.setString(
      _customFoodsKey,
      jsonEncode(_customFoods.map((f) => f.toJson()).toList()),
    );
  }

  Future<void> _saveTodayLog() async {
    await _prefs!
        .setString(_logKey(todayDate), jsonEncode(todayLog.toJson()));
    _checkAndUpdateStreak();
  }

  void _checkAndUpdateStreak() {
    final cal = todayLog.totalCalories;
    final prot = todayLog.totalProtein;
    final calPct = _goals.calories > 0 ? cal / _goals.calories : 0.0;
    final protPct = _goals.protein > 0 ? prot / _goals.protein : 0.0;
    final goalMet = calPct >= 0.9 && calPct <= 1.1 && protPct >= 0.85;

    if (goalMet && !_streak.completedDates.contains(todayDate)) {
      final newDates = [..._streak.completedDates, todayDate]..sort();
      final newCurrent = _calcStreak(newDates);
      _streak = NutritionStreak(current: newCurrent, completedDates: newDates);
      _prefs!.setString(_streakKey, jsonEncode(_streak.toJson()));
    }
  }

  int _calcStreak(List<String> sortedDates) {
    var streak = 0;
    var checkDate = todayDate;
    for (var i = sortedDates.length - 1; i >= 0; i--) {
      if (sortedDates[i] == checkDate) {
        streak++;
        checkDate = DateTime.parse(checkDate)
            .subtract(const Duration(days: 1))
            .toIso8601String()
            .substring(0, 10);
      } else {
        break;
      }
    }
    return streak;
  }

  List<({String date, double calories, bool goalMet})> getWeeklyHistory() {
    return List.generate(7, (i) {
      final d = DateTime.now().subtract(Duration(days: 6 - i));
      final dateStr = d.toIso8601String().substring(0, 10);
      double cal = 0;
      final raw = _prefs!.getString(_logKey(dateStr));
      if (raw != null) {
        try {
          cal = DailyNutritionLog.fromJson(
                  jsonDecode(raw) as Map<String, dynamic>)
              .totalCalories;
        } catch (_) {}
      }
      final pct = _goals.calories > 0 ? cal / _goals.calories : 0.0;
      return (date: dateStr, calories: cal, goalMet: pct >= 0.9 && pct <= 1.1);
    });
  }
}
