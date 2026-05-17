enum ClassStatus { available, fewSpots, waitlist, reserved, full }

class ClassSessionModel {
  final String id;
  final String name;
  final String type;
  final String instructor;
  final DateTime dateTime;
  final int durationMinutes;
  final int totalSpots;
  int bookedSpots;
  ClassStatus status;
  bool isReserved;

  final String description;

  ClassSessionModel({
    required this.id,
    required this.name,
    required this.type,
    required this.instructor,
    required this.dateTime,
    required this.durationMinutes,
    required this.totalSpots,
    required this.bookedSpots,
    this.description = '',
    ClassStatus? status,
    this.isReserved = false,
  }) : status = status ?? computeStatus(bookedSpots, totalSpots);

  static ClassStatus computeStatus(int booked, int total) {
    if (booked >= total) return ClassStatus.full;
    if (total - booked <= 3) return ClassStatus.fewSpots;
    return ClassStatus.available;
  }

  int get availableSpots => totalSpots - bookedSpots;
}
