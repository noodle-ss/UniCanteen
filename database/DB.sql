-- Encrypt password
-- Roles:
-- U: User
-- V: Vendor
-- A: Administrator
CREATE TABLE Users (
ID INT PRIMARY KEY AUTO_INCREMENT,
email VARCHAR(60) UNIQUE NOT NULL,
password VARCHAR(255) NOT NULL,  
role ENUM('U', 'V', 'A') NOT NULL
);

CREATE TABLE Restaurants (
ID INT PRIMARY KEY AUTO_INCREMENT,
name VARCHAR(60) NOT NULL,
address VARCHAR(100),
owner_ID INT,
FOREIGN KEY (owner_ID) REFERENCES Users(ID)
);

CREATE TABLE Items (
ID INT PRIMARY KEY AUTO_INCREMENT,
name VARCHAR(60) NOT NULL,
description TEXT,
price DECIMAL(10, 2) NOT NULL,
isAvailable BOOLEAN DEFAULT TRUE,
restaurant_ID INT,
FOREIGN KEY (restaurant_ID) REFERENCES Restaurants(ID)
);
-- status:
-- P: Pending
-- PR: Preparing
-- R: Ready
-- C: Completed
CREATE TABLE Orders (
ID INT PRIMARY KEY AUTO_INCREMENT,
customer_ID INT,
restaurant_ID INT,
total_amount DECIMAL(10, 2) NOT NULL,
status ENUM('P', 'PR', 'R', 'C') DEFAULT 'P',
queue_number INT,
order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (customer_ID) REFERENCES Users(ID),
FOREIGN KEY (restaurant_ID) REFERENCES Restaurants(ID)
);

CREATE TABLE Order_ItemLine (
ID INT PRIMARY KEY AUTO_INCREMENT,
order_ID INT,
item_ID INT,
quantity INT NOT NULL,
FOREIGN KEY (order_ID) REFERENCES Orders(ID),
FOREIGN KEY (item_ID) REFERENCES Items(ID)
);

CREATE TABLE Ratings (
ID INT PRIMARY KEY AUTO_INCREMENT,
restaurant_ID INT,
rating DECIMAL(3, 2) CHECK (rating BETWEEN 1.0 AND 5.0),
review VARCHAR(255),
timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (restaurant_ID) REFERENCES Restaurants(ID)
);
