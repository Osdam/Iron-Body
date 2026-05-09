class UserModel {
  final String id;
  final String fullName;
  final String email;
  final String document;
  final String phone;
  final String goal;
  final double weight;
  final double height;
  final String planName;
  final DateTime membershipExpiry;
  final String avatarUrl;
  final int streak;
  final int workoutsCompleted;

  const UserModel({
    required this.id,
    required this.fullName,
    required this.email,
    required this.document,
    required this.phone,
    required this.goal,
    required this.weight,
    required this.height,
    required this.planName,
    required this.membershipExpiry,
    this.avatarUrl = '',
    this.streak = 0,
    this.workoutsCompleted = 0,
  });

  String get firstName => fullName.split(' ').first;

  MembershipStatus get membershipStatus {
    final days = membershipExpiry.difference(DateTime.now()).inDays;
    if (days < 0) return MembershipStatus.expired;
    if (days <= 7) return MembershipStatus.expiringSoon;
    return MembershipStatus.active;
  }

  int get daysRemaining =>
      membershipExpiry.difference(DateTime.now()).inDays.clamp(0, 9999);

  double get bmi => weight / ((height / 100) * (height / 100));
}

enum MembershipStatus { active, expiringSoon, expired }
