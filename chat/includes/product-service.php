<?php
require_once __DIR__ . '/../../includes/config.php';

/**
 * Product Service class to handle product-related operations
 */
class ProductService {
    private $conn;
    private $pdo;
    
    /**
     * Constructor initializes database connections
     */
    public function __construct() {
        global $conn, $pdo;
        $this->conn = $conn;
        $this->pdo = $pdo;
    }
    
    /**
     * Get product by ID
     * 
     * @param int $productId
     * @return array|null Product data or null if not found
     */
    public function getProductById($productId) {
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id 
                 WHERE p.id = ?";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$productId]);
        
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($product) {
            // Get product images
            $product['images'] = $this->getProductImages($productId);
            
            // Get product attributes
            $product['attributes'] = $this->getProductAttributes($productId);
        }
        
        return $product ?: null;
    }
    
    /**
     * Get product images
     * 
     * @param int $productId
     * @return array Images data
     */
    public function getProductImages($productId) {
        $query = "SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$productId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get product attributes
     * 
     * @param int $productId
     * @return array Attributes data
     */
    public function getProductAttributes($productId) {
        $query = "SELECT pa.*, a.name as attribute_name 
                 FROM product_attributes pa 
                 JOIN attributes a ON pa.attribute_id = a.id 
                 WHERE pa.product_id = ?";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$productId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Search products based on query parameters
     * 
     * @param array $params Search parameters
     * @return array Products matching search criteria
     */
    public function searchProducts($params) {
        $conditions = [];
        $values = [];
        
        // Base query
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id";
        
        // Build search conditions
        if (!empty($params['category_id'])) {
            $conditions[] = "p.category_id = ?";
            $values[] = $params['category_id'];
        }
        
        if (!empty($params['search'])) {
            $searchTerm = '%' . $params['search'] . '%';
            $conditions[] = "(p.name LIKE ? OR p.description LIKE ? OR p.sku LIKE ?)";
            $values[] = $searchTerm;
            $values[] = $searchTerm;
            $values[] = $searchTerm;
        }
        
        if (!empty($params['min_price'])) {
            $conditions[] = "p.price >= ?";
            $values[] = $params['min_price'];
        }
        
        if (!empty($params['max_price'])) {
            $conditions[] = "p.price <= ?";
            $values[] = $params['max_price'];
        }
        
        if (!empty($params['attributes'])) {
            $query .= " JOIN product_attributes pa ON p.id = pa.product_id";
            $attrConditions = [];
            
            foreach ($params['attributes'] as $attrId => $value) {
                $attrConditions[] = "(pa.attribute_id = ? AND pa.value = ?)";
                $values[] = $attrId;
                $values[] = $value;
            }
            
            if (!empty($attrConditions)) {
                $conditions[] = "(" . implode(" OR ", $attrConditions) . ")";
            }
        }
        
        // Add status condition for active products
        $conditions[] = "p.status = 1";
        
        // Add conditions to query
        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }
        
        // Add ordering
        $orderBy = !empty($params['order_by']) ? $params['order_by'] : 'p.sort_order';
        $orderDir = !empty($params['order_dir']) ? $params['order_dir'] : 'ASC';
        $query .= " ORDER BY {$orderBy} {$orderDir}";
        
        // Add limit
        $limit = !empty($params['limit']) ? (int)$params['limit'] : 10;
        $query .= " LIMIT {$limit}";
        
        // Execute query
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($values);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get featured products
     * 
     * @param int $limit Number of products to return
     * @return array Featured products
     */
    public function getFeaturedProducts($limit = 6) {
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id 
                 WHERE p.featured = 1 AND p.status = 1 
                 ORDER BY p.sort_order 
                 LIMIT ?";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get latest products
     * 
     * @param int $limit Number of products to return
     * @return array Latest products
     */
    public function getLatestProducts($limit = 6) {
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id 
                 WHERE p.status = 1 
                 ORDER BY p.created_at DESC 
                 LIMIT ?";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get related products based on category and tags
     * 
     * @param int $productId Current product ID
     * @param int $limit Number of products to return
     * @return array Related products
     */
    public function getRelatedProducts($productId, $limit = 4) {
        // Get the current product's category
        $product = $this->getProductById($productId);
        
        if (!$product) {
            return [];
        }
        
        $categoryId = $product['category_id'];
        
        // Get products from the same category excluding the current product
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id 
                 WHERE p.category_id = ? AND p.id != ? AND p.status = 1 
                 ORDER BY RAND() 
                 LIMIT ?";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$categoryId, $productId, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get product recommendations based on user preferences
     * 
     * @param array $preferences User preferences
     * @param int $limit Number of products to return
     * @return array Recommended products
     */
    public function getRecommendedProducts($preferences, $limit = 5) {
        $conditions = [];
        $values = [];
        
        // Base query
        $query = "SELECT p.*, c.name as category_name 
                 FROM products p 
                 LEFT JOIN categories c ON p.category_id = c.id 
                 WHERE p.status = 1";
        
        // Process preferences
        if (!empty($preferences['categories'])) {
            $placeholders = implode(',', array_fill(0, count($preferences['categories']), '?'));
            $conditions[] = "p.category_id IN ({$placeholders})";
            $values = array_merge($values, $preferences['categories']);
        }
        
        if (!empty($preferences['price_range'])) {
            if (isset($preferences['price_range']['min'])) {
                $conditions[] = "p.price >= ?";
                $values[] = $preferences['price_range']['min'];
            }
            
            if (isset($preferences['price_range']['max'])) {
                $conditions[] = "p.price <= ?";
                $values[] = $preferences['price_range']['max'];
            }
        }
        
        if (!empty($preferences['attributes'])) {
            $query .= " JOIN product_attributes pa ON p.id = pa.product_id";
            $attrConditions = [];
            
            foreach ($preferences['attributes'] as $attribute) {
                if (isset($attribute['id']) && isset($attribute['value'])) {
                    $attrConditions[] = "(pa.attribute_id = ? AND pa.value LIKE ?)";
                    $values[] = $attribute['id'];
                    $values[] = '%' . $attribute['value'] . '%';
                }
            }
            
            if (!empty($attrConditions)) {
                $conditions[] = "(" . implode(" OR ", $attrConditions) . ")";
            }
        }
        
        // Add conditions to query
        if (!empty($conditions)) {
            $query .= " AND " . implode(" AND ", $conditions);
        }
        
        // Add ranking based on preferences
        $query .= " ORDER BY";
        
        if (!empty($preferences['featured'])) {
            $query .= " p.featured DESC,";
        }
        
        if (!empty($preferences['new_arrivals'])) {
            $query .= " p.created_at DESC,";
        }
        
        // Default ordering
        $query .= " p.sort_order, p.id DESC";
        
        // Add limit
        $query .= " LIMIT ?";
        $values[] = $limit;
        
        // Execute query
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($values);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Extract product preferences from user message
     * 
     * @param string $message User message
     * @return array Extracted preferences
     */
    public function extractPreferencesFromMessage($message) {
        $preferences = [
            'categories' => [],
            'price_range' => [],
            'attributes' => [],
            'featured' => false,
            'new_arrivals' => false
        ];
        
        // Get all categories
        $categories = $this->getCategories();
        $categoryPatterns = [];
        
        foreach ($categories as $category) {
            $categoryPatterns[$category['id']] = [
                'ar' => $category['name_ar'] ?: $category['name'],
                'en' => $category['name']
            ];
        }
        
        // Extract category preferences
        foreach ($categoryPatterns as $categoryId => $names) {
            $arabicName = preg_quote($names['ar'], '/');
            $englishName = preg_quote($names['en'], '/');
            
            if (preg_match("/\b({$arabicName}|{$englishName})\b/ui", $message)) {
                $preferences['categories'][] = $categoryId;
            }
        }
        
        // Extract price range
        if (preg_match('/(\d+)\s*-\s*(\d+)\s*(درهم|دراهم|درهماً|MAD|DH)/ui', $message, $matches)) {
            $preferences['price_range']['min'] = (float)$matches[1];
            $preferences['price_range']['max'] = (float)$matches[2];
        } elseif (preg_match('/أقل من\s*(\d+)\s*(درهم|دراهم|درهماً|MAD|DH)/ui', $message, $matches)) {
            $preferences['price_range']['max'] = (float)$matches[1];
        } elseif (preg_match('/أكثر من\s*(\d+)\s*(درهم|دراهم|درهماً|MAD|DH)/ui', $message, $matches)) {
            $preferences['price_range']['min'] = (float)$matches[1];
        } elseif (preg_match('/less than\s*(\d+)\s*(MAD|DH|درهم)/ui', $message, $matches)) {
            $preferences['price_range']['max'] = (float)$matches[1];
        } elseif (preg_match('/more than\s*(\d+)\s*(MAD|DH|درهم)/ui', $message, $matches)) {
            $preferences['price_range']['min'] = (float)$matches[1];
        }
        
        // Check for featured products request
        if (preg_match('/(featured|best|recommended|مميز|أفضل|موصى به)/ui', $message)) {
            $preferences['featured'] = true;
        }
        
        // Check for new arrivals request
        if (preg_match('/(new|latest|recent|جديد|حديث|وصل حديثاً)/ui', $message)) {
            $preferences['new_arrivals'] = true;
        }
        
        // Extract common attributes (color, size, material, etc.)
        // Colors
        $colorPatterns = [
            'red' => ['red', 'أحمر', 'حمراء'],
            'blue' => ['blue', 'أزرق', 'زرقاء'],
            'green' => ['green', 'أخضر', 'خضراء'],
            'black' => ['black', 'أسود', 'سوداء'],
            'white' => ['white', 'أبيض', 'بيضاء'],
            'yellow' => ['yellow', 'أصفر', 'صفراء'],
            'orange' => ['orange', 'برتقالي', 'برتقالية'],
            'purple' => ['purple', 'بنفسجي', 'بنفسجية'],
            'gray' => ['gray', 'grey', 'رمادي', 'رمادية']
        ];
        
        foreach ($colorPatterns as $color => $terms) {
            $pattern = implode('|', array_map(function($term) { return preg_quote($term, '/'); }, $terms));
            if (preg_match("/\b({$pattern})\b/ui", $message)) {
                $preferences['attributes'][] = [
                    'id' => 1, // Assuming 1 is the ID for color attribute
                    'name' => 'color',
                    'value' => $color
                ];
            }
        }
        
        // Sizes
        if (preg_match('/\b(small|medium|large|extra large|s|m|l|xl|xxl|صغير|متوسط|كبير|إكس لارج)\b/ui', $message, $matches)) {
            $sizeMap = [
                'small' => 'S', 's' => 'S', 'صغير' => 'S',
                'medium' => 'M', 'm' => 'M', 'متوسط' => 'M',
                'large' => 'L', 'l' => 'L', 'كبير' => 'L',
                'extra large' => 'XL', 'xl' => 'XL', 'إكس لارج' => 'XL',
                'xxl' => 'XXL'
            ];
            
            $size = strtolower($matches[1]);
            $preferences['attributes'][] = [
                'id' => 2, // Assuming 2 is the ID for size attribute
                'name' => 'size',
                'value' => $sizeMap[$size] ?? $size
            ];
        }
        
        return $preferences;
    }
    
    /**
     * Get all categories
     * 
     * @return array All categories
     */
    public function getCategories() {
        $query = "SELECT * FROM categories ORDER BY sort_order";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Format product for display in chat
     * 
     * @param array $product Product data
     * @param string $language Language code (ar, en)
     * @return string Formatted product HTML
     */
    public function formatProductForChat($product, $language = 'ar') {
        $siteUrl = SITE_URL;
        $productUrl = "{$siteUrl}/product-details.php?id={$product['id']}";
        
        $name = $language == 'ar' && !empty($product['name_ar']) ? $product['name_ar'] : $product['name'];
        $description = $language == 'ar' && !empty($product['description_ar']) ? $product['description_ar'] : $product['description'];
        $description = mb_substr(strip_tags($description), 0, 100) . '...';
        
        $price = number_format($product['price'], 2) . ' ' . DEFAULT_CURRENCY;
        $oldPrice = '';
        
        if (!empty($product['old_price']) && $product['old_price'] > $product['price']) {
            $oldPrice = "<span class=\"old-price\">" . number_format($product['old_price'], 2) . ' ' . DEFAULT_CURRENCY . "</span>";
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
