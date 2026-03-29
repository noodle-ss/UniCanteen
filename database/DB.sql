CREATE DATABASE IF NOT EXISTS unicanteen;
USE unicanteen;

DROP TABLE IF EXISTS Item_Categories;
DROP TABLE IF EXISTS Categories;
DROP TABLE IF EXISTS Order_ItemLine;
DROP TABLE IF EXISTS Ratings;
DROP TABLE IF EXISTS Favorites;
DROP TABLE IF EXISTS Orders;
DROP TABLE IF EXISTS Sessions;
DROP TABLE IF EXISTS UserLogs;
DROP TABLE IF EXISTS Items;
DROP TABLE IF EXISTS Restaurants;
DROP TABLE IF EXISTS Users;

-- ==================== USERS TABLE ====================
CREATE TABLE Users (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(60) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('U', 'V', 'A') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    is_banned BOOLEAN DEFAULT FALSE,
    last_login TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    security_question VARCHAR(255) NULL,
    security_answer VARCHAR(255) NULL
);

-- ==================== SESSIONS TABLE ====================
-- Fixed: expires_at is NOT NULL and must be provided
CREATE TABLE Sessions (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES Users(ID) ON DELETE CASCADE
);

-- ==================== USER LOGS TABLE ====================
CREATE TABLE UserLogs (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(ID) ON DELETE SET NULL
);

-- ==================== RESTAURANTS TABLE ====================
CREATE TABLE Restaurants (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    address VARCHAR(100),
    description TEXT,
    logo_url VARCHAR(255),
    owner_ID INT,
    is_open BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_ID) REFERENCES Users(ID)
);

-- ==================== ITEMS TABLE ====================
CREATE TABLE Items (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    image_url VARCHAR(255),
    isAvailable BOOLEAN DEFAULT TRUE,
    restaurant_ID INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_ID) REFERENCES Restaurants(ID) ON DELETE CASCADE
);

-- ==================== CATEGORIES TABLE ====================
CREATE TABLE Categories (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    description TEXT
);

-- ==================== ITEM_CATEGORIES TABLE ====================
CREATE TABLE Item_Categories (
    item_ID INT,
    category_ID INT,
    PRIMARY KEY (item_ID, category_ID),
    FOREIGN KEY (item_ID) REFERENCES Items(ID) ON DELETE CASCADE,
    FOREIGN KEY (category_ID) REFERENCES Categories(ID) ON DELETE CASCADE
);

-- ==================== ORDERS TABLE ====================
CREATE TABLE Orders (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    customer_ID INT,
    walkin_name VARCHAR(100) NULL,
    restaurant_ID INT,
    total_amount DECIMAL(10, 2) NOT NULL,
    status ENUM('P', 'PR', 'R', 'C') DEFAULT 'P',
    queue_number INT,
    payment_method ENUM('gcash', 'card', 'cash') DEFAULT 'gcash',
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    pickup_time TIME,
    FOREIGN KEY (customer_ID) REFERENCES Users(ID),
    FOREIGN KEY (restaurant_ID) REFERENCES Restaurants(ID)
);

-- ==================== ORDER_ITEMLINE TABLE ====================
CREATE TABLE Order_ItemLine (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    order_ID INT,
    item_ID INT,
    quantity INT NOT NULL,
    price_at_time DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (order_ID) REFERENCES Orders(ID) ON DELETE CASCADE,
    FOREIGN KEY (item_ID) REFERENCES Items(ID)
);

-- ==================== FAVORITES TABLE ====================
CREATE TABLE Favorites (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    item_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(ID) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES Items(ID) ON DELETE CASCADE,
    UNIQUE KEY unique_favorite (user_id, item_id)
);

-- ==================== RATINGS TABLE ====================
CREATE TABLE Ratings (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    restaurant_ID INT,
    order_ID INT UNIQUE,
    rating DECIMAL(3, 2) CHECK (rating BETWEEN 1.0 AND 5.0),
    review TEXT,
    is_anonymous BOOLEAN DEFAULT FALSE,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_ID) REFERENCES Restaurants(ID) ON DELETE CASCADE,
    FOREIGN KEY (order_ID) REFERENCES Orders(ID) ON DELETE CASCADE
);

-- ==================== INSERT SAMPLE DATA ====================

-- Insert categories
INSERT INTO Categories (name, description) VALUES
('Meals', 'Main course meals'),
('Beverages', 'Drinks and refreshments'),
('Snacks', 'Light snacks and sides'),
('Desserts', 'Sweet treats');

-- Insert sample users (password for all accounts: 'password123')
-- Security answer for all sample accounts: 'answer123'
-- Hash generated with: password_hash('password123', PASSWORD_DEFAULT)
-- Answer hash generated with: password_hash('answer123', PASSWORD_DEFAULT)
-- Customers: customer1@dlsu.edu.ph, customer2@dlsu.edu.ph
-- Vendors: bloemen@dlsu.edu.ph, agno@dlsu.edu.ph, kitchensj@dlsu.edu.ph, deli@dlsu.edu.ph
-- Admin: admin@dlsu.edu.ph
INSERT INTO Users (email, password, full_name, role, is_active, login_attempts, security_question, security_answer) VALUES
('customer1@dlsu.edu.ph', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.', 'Juan Dela Cruz', 'U', TRUE, 0, 'What is the name of your childhood pet?', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.'),
('customer2@dlsu.edu.ph', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.', 'Maria Santos', 'U', TRUE, 0, 'What city were you born in?', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.'),
('bloemen@dlsu.edu.ph', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.', 'Bloemen Hall Manager', 'V', TRUE, 0, 'What is your mother\'s maiden name?', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.'),
('agno@dlsu.edu.ph', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.', 'Agno Eatery Owner', 'V', TRUE, 0, 'What was the name of your first school?', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.'),
('kitchensj@dlsu.edu.ph', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.', 'Kitchen SJ Manager', 'V', TRUE, 0, 'What is the name of your childhood pet?', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.'),
('deli@dlsu.edu.ph', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.', 'St. La Salle Deli Owner', 'V', TRUE, 0, 'What was the name of your first school?', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.'),
('admin@dlsu.edu.ph', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.', 'System Administrator', 'A', TRUE, 0, 'What is your favorite book?', '$2y$10$qXcpNByl5VFDP3z9KAeXk.aBp0FopJsck70GKeoWxwPXnkFMuY44.'),
('walkinunicanteen@dlsu.edu.ph', '12345', 'Walk-in Users', 'U', TRUE, 0, NULL, NULL);

-- Insert restaurants (each owned by its corresponding vendor user)
-- Bloemen Hall -> bloemen@dlsu.edu.ph (ID=3)
-- Agno Eatery -> agno@dlsu.edu.ph (ID=4)
-- Kitchen SJ -> kitchensj@dlsu.edu.ph (ID=5)
-- St. La Salle Deli -> deli@dlsu.edu.ph (ID=6)
INSERT INTO Restaurants (name, address, description, owner_ID, is_open) VALUES
('Bloemen Hall', 'Bloemen Hall, DLSU', 'Fresh meals and coffee', 3, TRUE),
('Agno Eatery', 'Agno Food Court, DLSU', 'Traditional Filipino favorites', 4, TRUE),
('Kitchen SJ', 'St. La Salle Building, DLSU', 'Italian and pasta dishes', 5, TRUE),
('St. La Salle Deli', 'St. La Salle Hall, DLSU', 'Sandwiches and salads', 6, TRUE);

-- Insert items for Bloemen Hall (restaurant_ID = 1)
INSERT INTO Items (name, description, price, image_url, isAvailable, restaurant_ID) VALUES
('Chicken Bowl', 'Grilled chicken with rice and vegetables', 145.00, 'assets/uploads/food_69ac419b9d49a5.98156890.jpg', TRUE, 1),
('Iced Latte', 'Espresso with milk and ice', 110.00, 'assets/uploads/food_69ac41acf056a8.54984326.jpg', TRUE, 1),
('Garlic Fries', 'French fries with garlic and parsley', 75.00, 'assets/uploads/item_69b755384262a5.24136950.jpg', TRUE, 1),
('Breakfast Meal', 'Eggs, rice, and choice of meat', 155.00, 'assets/uploads/item_69b755571acd83.60899894.jpg', TRUE, 1),
('Pork Sinigang', 'Pork in sour tamarind soup', 160.00, '', TRUE, 1),
('Leche Flan', 'Rich caramel custard dessert', 65.00, '', TRUE, 1);

-- Insert items for Agno Eatery (restaurant_ID = 2)
INSERT INTO Items (name, description, price, image_url, isAvailable, restaurant_ID) VALUES
('Beef Tapa', 'Marinated beef with garlic rice and egg', 135.00, 'assets/uploads/item_69b7559ee91123.20573314.jpg', TRUE, 2),
('Garlic Rice', 'Fried rice with garlic', 35.00, 'assets/uploads/item_69b755b9816061.03910626.jpeg', TRUE, 2),
('Lumpiang Shanghai', 'Fried spring rolls with pork', 80.00, 'assets/uploads/item_69b755cef16094.37442931.webp', FALSE, 2),
('Bangus Sisig', 'Milkfish sisig', 140.00, 'assets/uploads/item_69b755f9b4cf68.59500060.jpg', TRUE, 2),
('Pork BBQ', 'Sweet and savory grilled pork skewer', 45.00, '', TRUE, 2),
('Tocilog', 'Sweet cured pork with garlic rice and egg', 120.00, '', TRUE, 2);

-- Insert items for Kitchen SJ (restaurant_ID = 3)
INSERT INTO Items (name, description, price, image_url, isAvailable, restaurant_ID) VALUES
('Lasagna', 'Classic meat lasagna', 180.00, 'assets/uploads/item_69b75688704017.13622961.jpg', FALSE, 3),
('Garlic Bread', 'Toasted bread with garlic butter', 45.00, 'assets/uploads/item_69b7569ba351c4.28979106.jpg', TRUE, 3),
('Pesto Pasta', 'Pasta with basil pesto sauce', 150.00, 'assets/uploads/item_69b756b1861385.35205079.jpg', FALSE, 3),
('Tiramisu', 'Italian coffee dessert', 120.00, 'assets/uploads/item_69b756c15ec1f2.07308014.webp', TRUE, 3),
('Carbonara', 'Creamy white sauce with bacon bits', 160.00, '', TRUE, 3),
('Mango Graham', 'Chilled layered graham crackers and mango', 95.00, '', TRUE, 3);

-- Insert items for St. La Salle Deli (restaurant_ID = 4)
INSERT INTO Items (name, description, price, image_url, isAvailable, restaurant_ID) VALUES
('Club Sandwich', 'Triple-layer sandwich with fries', 130.00, 'assets/uploads/item_69b7562f1ded05.23241086.jpg', TRUE, 4),
('Fruit Shake', 'Fresh fruit shake', 95.00, 'assets/uploads/item_69b756417383b9.38783976.jpg', TRUE, 4),
('Caesar Salad', 'Romaine lettuce with Caesar dressing', 140.00, 'assets/uploads/item_69b7565059dc18.61200073.jpg', FALSE, 4),
('Iced Tea', 'Fresh brewed iced tea', 55.00, 'assets/uploads/item_69b75665d5aeb3.74195366.jpeg', TRUE, 4),
('Tuna Sandwich', 'Toasted bread with creamy tuna spread', 95.00, '', TRUE, 4),
('Lemonade', 'Freshly squeezed lemons', 65.00, '', TRUE, 4);

-- Insert sample orders
INSERT INTO Orders (customer_ID, restaurant_ID, total_amount, status, queue_number, payment_method, order_date) VALUES
(1, 1, 300.00, 'PR', 1, 'gcash', NOW() - INTERVAL 1 HOUR),
(2, 2, 170.00, 'R', 1, 'gcash', NOW() - INTERVAL 2 HOUR),
(1, 3, 165.00, 'C', 1, 'card', NOW() - INTERVAL 3 HOUR);

-- Insert order items
INSERT INTO Order_ItemLine (order_ID, item_ID, quantity, price_at_time) VALUES
(1, 1, 1, 145.00),
(1, 2, 1, 110.00),
(1, 10, 1, 45.00),
(2, 5, 1, 135.00),
(2, 6, 1, 35.00),
(3, 10, 1, 45.00),
(3, 12, 1, 120.00);

-- Insert sample ratings
INSERT INTO Ratings (restaurant_ID, order_ID, rating, review, timestamp) VALUES
(2, 2, 4.5, 'grabe super sarap nung tapa, tapos dambuhala pa ung serving. busog na busog me haha sulit.', NOW() - INTERVAL 2 HOUR),
(3, 3, 5.0, 'literal na pumuputok ung lasa ng garlic bread nila hahah fav ko to!', NOW() - INTERVAL 3 HOUR),
(4, NULL, 5.0, 'uyy legit ang laki nung club sandwich nila, di ko naubos. mabait din si kuya sa counter', NOW() - INTERVAL 5 HOUR),
(1, NULL, 4.0, 'okay sana iced latte kaso mejo matapang, pero saktong pampa-gising sa hapon. mabilis din nakuha', NOW() - INTERVAL 1 DAY),
(2, NULL, 5.0, 'agghhh the best ung bangus sisig nila! always bumabalik para dito', NOW() - INTERVAL 2 DAY),
(3, NULL, 3.5, 'mediocre lang yung pesto for me ewan ko bat hype hahah pero oki naman din', NOW() - INTERVAL 2 DAY),
(1, NULL, 5.0, 'super comforting nung sinigang as in, lalo na pag umuulan!! bibilis pa nung staff', NOW() - INTERVAL 3 DAY),
(4, NULL, 4.5, 'fruit shake saves lives ahaha ang init nung hapon grabe. saraaappp!', NOW() - INTERVAL 4 DAY);

-- Insert item categories
INSERT INTO Item_Categories (item_ID, category_ID) VALUES
(1, 1), (2, 2), (3, 3), (4, 1),
(5, 1), (6, 3), (7, 3), (8, 1),
(9, 1), (10, 3), (11, 1), (12, 4),
(13, 1), (14, 2), (15, 1), (16, 2),
(17, 1), (18, 4), (19, 3), (20, 1),
(21, 1), (22, 4), (23, 3), (24, 2);

-- Create indexes for better performance
CREATE INDEX idx_users_email ON Users(email);
CREATE INDEX idx_users_role ON Users(role);
CREATE INDEX idx_restaurants_owner ON Restaurants(owner_ID);
CREATE INDEX idx_restaurants_is_open ON Restaurants(is_open);
CREATE INDEX idx_items_restaurant ON Items(restaurant_ID);
CREATE INDEX idx_items_available ON Items(isAvailable);
CREATE INDEX idx_orders_customer ON Orders(customer_ID);
CREATE INDEX idx_orders_restaurant ON Orders(restaurant_ID);
CREATE INDEX idx_orders_status ON Orders(status);
CREATE INDEX idx_orders_date ON Orders(order_date);
CREATE INDEX idx_ratings_restaurant ON Ratings(restaurant_ID);
CREATE INDEX idx_order_itemline_order ON Order_ItemLine(order_ID);
CREATE INDEX idx_sessions_token ON Sessions(session_token);
CREATE INDEX idx_sessions_expires ON Sessions(expires_at);
CREATE INDEX idx_user_logs_user_id ON UserLogs(user_id);
CREATE INDEX idx_user_logs_timestamp ON UserLogs(timestamp);