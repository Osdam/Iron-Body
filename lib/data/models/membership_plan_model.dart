class MembershipPlanModel {
  final String id;
  final String name;
  final String period;
  final int months;
  final double price;
  final double? originalPrice;
  final List<String> benefits;
  final bool isRecommended;
  final String badge;

  const MembershipPlanModel({
    required this.id,
    required this.name,
    required this.period,
    required this.months,
    required this.price,
    this.originalPrice,
    required this.benefits,
    this.isRecommended = false,
    this.badge = '',
  });

  double get monthlyPrice => price / months;
  int get savingPercent => originalPrice != null
      ? (((originalPrice! - price) / originalPrice!) * 100).round()
      : 0;
}
