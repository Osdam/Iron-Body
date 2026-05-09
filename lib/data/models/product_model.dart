import 'package:flutter/material.dart';

class ProductModel {
  final String id;
  final String name;
  final String category;
  final double price;
  final int stock;
  final String description;
  final IconData iconData;

  const ProductModel({
    required this.id,
    required this.name,
    required this.category,
    required this.price,
    required this.stock,
    required this.description,
    required this.iconData,
  });

  bool get isLowStock => stock > 0 && stock <= 5;
  bool get isAvailable => stock > 0;
}

class CartItem {
  final ProductModel product;
  int quantity;

  CartItem({required this.product, this.quantity = 1});

  double get subtotal => product.price * quantity;
}
