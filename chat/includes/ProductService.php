<?php
require_once __DIR__ . '/Database.php';

class ProductService {
    private $db;
    private $config;
    
    public function __construct() {
        global $config;
        $this->config = $config;
        $this->db = Database::getInstance($config)->getConnection();
        
        // Ensure product recommendations table exists
        $this->createRecommendationsTable();
    }
    
    // Create product recommendations table if it doesn't exist
    private function createRecommendationsTable() {
        $this->db->query("CREATE TABLE IF NOT EXISTS chat_product_recommendations (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) NOT NULL,
            product_id INT NOT NULL,
            category VARCHAR(100),
            score FLOAT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES chat_users(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    
    // Get products from the main website database
    public function getProducts($filters = [], $limit = 10) {
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id 
                 WHERE p.status = 1";
                 
        $params = [];
        
        // Add category filter
        if (isset($filters['category_id']) && !empty($filters['category_id'])) {
            $query .= " AND p.category_id = ?";
            $params[] = $filters['category_id'];
        }
        
        // Add search term filter
        if (isset($filters['search']) && !empty($filters['search'])) {
            $query .= " AND (p.name LIKE ? OR p.name_ar LIKE ? OR p.description LIKE ? OR p.description_ar LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Add price range filters
        if (isset($filters['min_price']) && !empty($filters['min_price'])) {
            $query .= " AND p.price >= ?";
            $params[] = $filters['min_price'];
        }
        
        if (isset($filters['max_price']) && !empty($filters['max_price'])) {
            $query .= " AND p.price <= ?";
            $params[] = $filters['max_price'];
        }
        
        // Order by
        $orderBy = isset($filters['order_by']) ? $filters['order_by'] : 'p.sort_order';
        $orderDir = isset($filters['order_dir']) ? $filters['order_dir'] : 'ASC';
        $query .= " ORDER BY $orderBy $orderDir";
        
        // Add limit
        $query .= " LIMIT ?";
        $params[] = $limit;
        
        // Execute query
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        // Bind parameters
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        
        if (!empty($params)) {
            $bindParams = array_merge([$types], $params);
            call_user_func_array([$stmt, 'bind_param'], $this->refValues($bindParams));
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        
        $stmt->close();
        
        // Get additional product details
        foreach ($products as &$product) {
            $product['images'] = $this->getProductImages($product['id']);
        }
        
        return $products;
    }
    
    // Get product images
    private function getProductImages($productId) {
        $query = "SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order";
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $images = [];
        while ($row = $result->fetch_assoc()) {
            $images[] = $row;
        }
        
        $stmt->close();
        return $images;
    }
    
    // Helper function for binding parameters by reference
    private function refValues($arr) {
        $refs = [];
        foreach ($arr as $key => $value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    }
    
    // Save product recommendations for a user
    public function saveRecommendations($userId, $recommendations) {
        if (empty($recommendations)) {
            return false;
        }
        
        // Clear existing recommendations
        $userId = $this->db->real_escape_string($userId);
        $this->db->query("DELETE FROM chat_product_recommendations WHERE user_id = '$userId'");
        
        // Insert new recommendations
        $stmt = $this->db->prepare("INSERT INTO chat_product_recommendations 
                                  (user_id, product_id, category, score) 
                                  VALUES (?, ?, ?, ?)");
        
        foreach ($recommendations as $index => $product) {
            $score = 1.0 - ($index * 0.1); // Higher score for first products
            if ($score < 0.1) $score = 0.1;
            
            $category = $product['category_name'] ?? '';
            
            $stmt->bind_param('sids', $userId, $product['id'], $category, $score);
            $stmt->execute();
        }
        
        $stmt->close();
        return true;
    }
    
    // Get recommendations for a user
    public function getRecommendationsForUser($userId, $limit = 5) {
        $userId = $this->db->real_escape_string($userId);
        
        $query = "SELECT pr.product_id, pr.score FROM chat_product_recommendations pr 
                 WHERE pr.user_id = ? 
                 ORDER BY pr.score DESC, pr.created_at DESC 
                 LIMIT ?";
                 
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param('si', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $productIds = [];
        while ($row = $result->fetch_assoc()) {
            $productIds[] = $row['product_id'];
        }
        
        $stmt->close();
        
        if (empty($productIds)) {
            return [];
        }
        
        // Get product details
        $products = [];
        foreach ($productIds as $productId) {
            $productDetails = $this->getProductDetails($productId);
            if ($productDetails) {
                $products[] = $productDetails;
            }
        }
        
        return $products;
    }
    
    // Get details of a single product
    public function getProductDetails($productId) {
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id 
                 WHERE p.id = ?";
                 
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            return null;
        }
        
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $product = $result->fetch_assoc();
        $stmt->close();
        
        if (!$product) {
            return null;
        }
        
        // Get product images
        $product['images'] = $this->getProductImages($productId);
        
        return $product;
    }
    
    // Generate recommendations based on user messages
    public function generateRecommendationsFromMessages($userId, $messages) {
        $keywords = [];
        $categories = [];
        $priceRange = ['min' => null, 'max' => null];
        
        // Extract search terms from messages
        foreach ($messages as $message) {
            // Extract keywords
            $words = preg_split('/\s+/', strtolower($message));
            foreach ($words as $word) {
                if (strlen($word) > 3 && !in_array($word, ['this', 'that', 'with', 'from', 'have', 'want', 'need', 'like', 'about'])) {
                    $keywords[] = $word;
                }
            }
            
            // Extract categories
            $categoryPatterns = [
                '/\b(phone|smartphone|mobile|جوال|هاتف|موبايل)\b/ui' => 1, // assuming 1 is phone category ID
                '/\b(laptop|notebook|computer|حاسوب|لابتوب|كمبيوتر)\b/ui' => 2, // assuming 2 is laptop category ID
                '/\b(tv|television|تلفاز|تلفزيون)\b/ui' => 3, // assuming 3 is TV category ID
                '/\b(tablet|ipad|تابلت|لوحي|جهاز لوحي)\b/ui' => 4, // assuming 4 is tablet category ID
            ];
            
            foreach ($categoryPatterns as $pattern => $categoryId) {
                if (preg_match($pattern, $message)) {
                    $categories[] = $categoryId;
                }
            }
            
            // Extract price range
            if (preg_match('/\b(under|less than|اقل من|تحت)\s*(\d+)\b/ui', $message, $matches)) {
                $priceRange['max'] = (int)$matches[2];
            }
            
            if (preg_match('/\b(over|more than|اكثر من|فوق)\s*(\d+)\b/ui', $message, $matches)) {
                $priceRange['min'] = (int)$matches[2];
            }
            
            if (preg_match('/\b(\d+)\s*-\s*(\d+)\b/u', $message, $matches)) {
                $priceRange['min'] = (int)$matches[1];
                $priceRange['max'] = (int)$matches[2];
            }
        }
        
        // Build filters
        $filters = [];
        
        // Add search terms
        if (!empty($keywords)) {
            $filters['search'] = implode(' ', array_unique($keywords));
        }
        
        // Add category filter (use the most frequent category)
        if (!empty($categories)) {
            $categoryCount = array_count_values($categories);
            arsort($categoryCount);
            $filters['category_id'] = key($categoryCount);
        }
        
        // Add price range
        if ($priceRange['min'] !== null) {
            $filters['min_price'] = $priceRange['min'];
        }
        
        if ($priceRange['max'] !== null) {
            $filters['max_price'] = $priceRange['max'];
        }
        
        // Get matching products
        $products = $this->getProducts($filters, 5);
        
        // If no specific filters matched, get featured products
        if (empty($products)) {
            $products = $this->getProducts(['featured' => 1], 5);
        }
        
        // Save recommendations
        if (!empty($products)) {
            $this->saveRecommendations($userId, $products);
        }
        
        return $products;
    }
    
    // Format product for display in chat
    public function formatProductForChat($product, $language = 'ar') {
        $name = $language == 'ar' && !empty($product['name_ar']) ? $product['name_ar'] : $product['name'];
        $description = $language == 'ar' && !empty($product['description_ar']) ? $product['description_ar'] : $product['description'];
        $description = mb_substr(strip_tags($description), 0, 100) . '...';
        
        $siteUrl = $this->config['site']['url'];
        $productUrl = "{$siteUrl}/product-details.php?id={$product['id']}";
        
        $price = number_format($product['price'], 2) . ' ' . $this->config['site']['currency'];
        $oldPrice = '';
        
        if (!empty($product['old_price']) && $product['old_price'] > $product['price']) {
            $oldPrice = "<span class=\"old-price\">" . number_format($product['old_price'], 2) . ' ' . $this->config['site']['currency'] . "</span>";
        }
        
        $imageUrl = !empty($product['images'][0]['image_url']) 
            ? $siteUrl . '/' . $product['images'][0]['image_url'] 
            : $siteUrl . '/images/no-image.jpg';
        
        $inStockText = $language == 'ar' ? 'متوفر' : 'In Stock';
        $outOfStockText = $language == 'ar' ? 'غير متوفر' : 'Out of Stock';
        $stockStatus = $product['stock_quantity'] > 0 ? "<span class=\"in-stock\">{$inStockText}</span>" : "<span class=\"out-of-stock\">{$outOfStockText}</span>";
        
        $viewProductText = $language == 'ar' ? 'عرض المنتج' : 'View Product';
        
        $html = <<<HTML
        <div class="product-card" data-product-id="{$product['id']}">
            <div class="product-image">
                <img src="{$imageUrl}" alt="{$name}">
            </div>
            <div class="product-info">
                <h3>{$name}</h3>
                <p>{$description}</p>
                <div class="product-price">
                    <span class="price">{$price}</span>
                    {$oldPrice}
                </div>
                <div class="product-stock">
                    {$stockStatus}
                </div>
                <a href="{$productUrl}" class="view-product" target="_blank">{$viewProductText}</a>
            </div>
        </div>
        HTML;
        
        return $html;
    }
}
