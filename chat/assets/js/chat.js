document.addEventListener('DOMContentLoaded', function() {
    const chatForm = document.getElementById('chatForm');
    const messageInput = document.getElementById('messageInput');
    const chatMessages = document.getElementById('chatMessages');
    const typingIndicator = document.getElementById('typingIndicator');
    const recommendationsContainer = document.getElementById('recommendationsContainer');
    const languageOptions = document.querySelectorAll('.language-option');
    
    // Set direction based on language
    function setDirection(language) {
        document.documentElement.lang = language;
        document.documentElement.dir = (language === 'ar') ? 'rtl' : 'ltr';
        document.body.className = (language === 'ar') ? 'rtl' : 'ltr';
    }
    
    // Initialize direction based on current language
    const currentLanguage = document.documentElement.lang;
    setDirection(currentLanguage);
    
    // Scroll to bottom of chat
    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // Add message to chat
    function addMessage(content, isUser = false, intent = null) {
        const messageDiv = document.createElement('div');
        messageDiv.className = isUser ? 'message user-message' : 'message ai-message';
        
        // Add intent badge if available and it's a user message
        if (intent && isUser) {
            const intentBadge = document.createElement('div');
            intentBadge.className = 'intent-badge';
            intentBadge.textContent = intent;
            messageDiv.appendChild(intentBadge);
        }
        
        // Create message content
        const messageContent = document.createElement('div');
        messageContent.className = 'message-content';
        
        // Handle HTML content
        if (/<div class="product-card"|<img|<a /.test(content)) {
            // If the content contains product cards or HTML elements, insert it as HTML
            messageContent.innerHTML = content;
        } else {
            // Otherwise, escape and add line breaks for plain text
            messageContent.innerHTML = content.replace(/\n/g, '<br>');
        }
        
        messageDiv.appendChild(messageContent);
        
        // Add timestamp
        const timeDiv = document.createElement('div');
        timeDiv.className = 'message-time';
        const now = new Date();
        timeDiv.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        messageDiv.appendChild(timeDiv);
        
        chatMessages.insertBefore(messageDiv, typingIndicator);
        scrollToBottom();
    }
    
    // Update recommendations
    function updateRecommendations(recommendations) {
        if (!recommendations || recommendations.length === 0) return;
        
        recommendationsContainer.innerHTML = '';
        
        const language = document.documentElement.lang;
        const inquireText = language === 'ar' ? 'انقر للاستفسار' : 'Click to inquire';
        
        recommendations.forEach(product => {
            const itemDiv = document.createElement('div');
            itemDiv.className = 'recommendation-item';
            itemDiv.dataset.productId = product.id;
            
            const thumbDiv = document.createElement('div');
            thumbDiv.className = 'recommendation-thumb';
            
            const img = document.createElement('img');
            img.src = product.image ? (window.location.origin + '/' + product.image) : (window.location.origin + '/images/no-image.jpg');
            img.alt = product.name;
            thumbDiv.appendChild(img);
            
            const infoDiv = document.createElement('div');
            infoDiv.className = 'recommendation-info';
            
            const header = document.createElement('h4');
            header.textContent = product.name;
            
            const paragraph = document.createElement('p');
            paragraph.textContent = inquireText;
            
            const priceDiv = document.createElement('div');
            priceDiv.className = 'recommendation-price';
            priceDiv.textContent = new Intl.NumberFormat(language === 'ar' ? 'ar-MA' : 'en-US', { 
                style: 'decimal',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(product.price) + ' ' + (product.currency || 'MAD');
            
            infoDiv.appendChild(header);
            infoDiv.appendChild(paragraph);
            infoDiv.appendChild(priceDiv);
            
            itemDiv.appendChild(thumbDiv);
            itemDiv.appendChild(infoDiv);
            
            recommendationsContainer.appendChild(itemDiv);
        });
        
        // Add click handlers to new recommendations
        attachRecommendationClickHandlers();
    }
    
    // Attach click handlers to recommendations
    function attachRecommendationClickHandlers() {
        const recommendationItems = document.querySelectorAll('.recommendation-item');
        recommendationItems.forEach(item => {
            item.addEventListener('click', function() {
                const productId = this.dataset.productId;
                const productName = this.querySelector('h4').textContent;
                
                const language = document.documentElement.lang;
                const query = language === 'ar' 
                    ? `أريد معلومات عن المنتج ${productName}`
                    : `I want information about the product ${productName}`;
                
                messageInput.value = query;
                messageInput.focus();
            });
        });
    }
    
    // Show typing indicator
    function showTypingIndicator() {
        typingIndicator.style.display = 'block';
        scrollToBottom();
    }
    
    // Hide typing indicator
    function hideTypingIndicator() {
        typingIndicator.style.display = 'none';
    }
    
    // Enhanced function to handle server errors
    function handleServerError(error) {
        hideTypingIndicator();
        
        console.error('Error:', error);
        
        // Determine if it's a network error
        let isNetworkError = false;
        let isTimeoutError = false;
        
        if (error instanceof TypeError && error.message.includes('NetworkError')) {
            isNetworkError = true;
        }
        
        if (error.name === 'TimeoutError' || (error.message && error.message.includes('timeout'))) {
            isTimeoutError = true;
        }
        
        const language = document.documentElement.lang;
        let errorMessage;
        
        if (isNetworkError) {
            errorMessage = language === 'ar' 
                ? 'عذرًا، يبدو أنك غير متصل بالإنترنت. يرجى التحقق من اتصالك والمحاولة مرة أخرى.'
                : 'Sorry, it seems you are offline. Please check your connection and try again.';
        } else if (isTimeoutError) {
            errorMessage = language === 'ar' 
                ? 'عذرًا، استغرقت العملية وقتًا طويلاً. يرجى المحاولة مرة أخرى لاحقًا.'
                : 'Sorry, the operation timed out. Please try again later.';
        } else {
            errorMessage = language === 'ar' 
                ? 'عذرًا، حدث خطأ في معالجة طلبك. يرجى المحاولة مرة أخرى.'
                : 'Sorry, there was an error processing your request. Please try again.';
        }
        
        addMessage(errorMessage);
    }
    
    // Enhanced fetch with timeout
    function fetchWithTimeout(url, options, timeout = 30000) {
        return Promise.race([
            fetch(url, options),
            new Promise((_, reject) => 
                setTimeout(() => reject(new Error('Request timeout')), timeout)
            )
        ]);
    }
    
    // Submit form with improved error handling
    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const message = messageInput.value.trim();
        if (!message) return;
        
        // Add user message to chat
        addMessage(message, true);
        messageInput.value = '';
        
        // Show typing indicator
        showTypingIndicator();
        
        // Send message to server with timeout
        // Sanitize message
        const sanitizedMessage = message.replace(/[<>]/g, '');
        if (sanitizedMessage !== message) {
            const errorMessage = document.documentElement.lang === 'ar'
                ? 'عذراً، الرسالة تحتوي على رموز غير مسموح بها'
                : 'Sorry, the message contains invalid characters';
            addMessage(errorMessage);
            return;
        }
        
        fetchWithTimeout(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: 'message=' + encodeURIComponent(sanitizedMessage)
        }, 60000) // 60-second timeout
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            // Hide typing indicator
            hideTypingIndicator();
            
            if (data.error) {
                const errorMessage = typeof data.error === 'string' ? data.error :
                    (document.documentElement.lang === 'ar'
                        ? 'عذراً، حدث خطأ في معالجة طلبك. يرجى المحاولة مرة أخرى.'
                        : 'Sorry, there was an error processing your request. Please try again.');
                addMessage(errorMessage);
                return;
            }
            
            // Validate response
            if (!data.response) {
                throw new Error('Invalid response format');
            }
            
            // Add AI response to chat
            addMessage(data.response);
            
            // Update recommendations if available
            if (data.recommendations && data.recommendations.length > 0) {
                updateRecommendations(data.recommendations);
            }
        })
        .catch(error => handleServerError(error));
    });
    
    // Language selection
    languageOptions.forEach(option => {
        option.addEventListener('click', function() {
            const language = this.dataset.lang;
            
            // Update active state
            document.querySelector('.language-option.active').classList.remove('active');
            this.classList.add('active');
            
            // Set direction
            setDirection(language);
            
            // Redirect to change language
            window.location.href = `${window.location.pathname}?lang=${language}`;
        });
    });
    
    // Handle recommendation clicks
    attachRecommendationClickHandlers();
    
    // Record session analytics when page is unloaded
    window.addEventListener('beforeunload', function() {
        const sessionDuration = Math.floor((new Date() - performance.timing.navigationStart) / 1000);
        const messageCount = document.querySelectorAll('.message').length;
        
        // Use sendBeacon to ensure the data is sent even if the page is closing
        navigator.sendBeacon(
            window.location.href, 
            new URLSearchParams({
                'analytics': 'session_end',
                'duration': sessionDuration,
                'messages_count': messageCount
            }).toString()
        );
    });
    
    // Initial scroll to bottom
    scrollToBottom();
    
    // Focus input field
    messageInput.focus();
});
