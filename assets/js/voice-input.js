// FixariVan Voice Input System
// Advanced voice recognition for faster form filling

class VoiceInputSystem {
    constructor() {
        this.recognition = null;
        this.isListening = false;
        this.currentField = null;
        this.supportedLanguages = ['ru-RU', 'en-US', 'fi-FI'];
        this.currentLanguage = 'ru-RU';
        this.templates = new Map();
        
        this.init();
    }
    
    init() {
        this.setupSpeechRecognition();
        this.setupVoiceTemplates();
        this.setupUI();
    }
    
    setupSpeechRecognition() {
        if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            console.warn('Speech recognition not supported');
            return;
        }
        
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        this.recognition = new SpeechRecognition();
        
        this.recognition.continuous = false;
        this.recognition.interimResults = false;
        this.recognition.lang = this.currentLanguage;
        this.recognition.maxAlternatives = 1;
        
        this.recognition.onstart = () => {
            this.isListening = true;
            this.updateUI();
        };
        
        this.recognition.onresult = (event) => {
            const result = event.results[0][0].transcript;
            this.processVoiceInput(result);
        };
        
        this.recognition.onerror = (event) => {
            console.error('Speech recognition error:', event.error);
            this.isListening = false;
            this.updateUI();
        };
        
        this.recognition.onend = () => {
            this.isListening = false;
            this.updateUI();
        };
    }
    
    setupVoiceTemplates() {
        // Common device problems
        this.templates.set('problems', [
            'не включается',
            'разбит экран',
            'не работает тач',
            'не заряжается',
            'не работает камера',
            'не работает звук',
            'медленно работает',
            'зависает',
            'не работает интернет',
            'не работает микрофон'
        ]);
        
        // Device models
        this.templates.set('models', [
            'iPhone 13',
            'iPhone 12',
            'iPhone 11',
            'Samsung Galaxy S21',
            'Samsung Galaxy S20',
            'Samsung Galaxy A52',
            'iPad Pro',
            'iPad Air',
            'MacBook Pro',
            'MacBook Air'
        ]);
        
        // Client names (common patterns)
        this.templates.set('names', [
            'Иван Петров',
            'Мария Сидорова',
            'Алексей Козлов',
            'Елена Морозова',
            'Дмитрий Волков'
        ]);
        
        // Quick phrases
        this.templates.set('phrases', [
            'заменить экран',
            'починить кнопку',
            'заменить батарею',
            'восстановить данные',
            'установить программу',
            'настроить интернет'
        ]);
    }
    
    setupUI() {
        // Add voice input buttons to forms
        this.addVoiceButtons();
        
        // Add language selector
        this.addLanguageSelector();
        
        // Add quick templates
        this.addQuickTemplates();
    }
    
    addVoiceButtons() {
        const textInputs = document.querySelectorAll('input[type="text"], textarea');
        
        textInputs.forEach(input => {
            if (input.closest('.form-group')) {
                const voiceBtn = this.createVoiceButton(input);
                input.parentNode.appendChild(voiceBtn);
            }
        });
    }
    
    createVoiceButton(input) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'voice-btn';
        btn.innerHTML = '🎤';
        btn.title = 'Голосовой ввод';
        btn.style.cssText = `
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: #1C5FDD;
            color: white;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            cursor: pointer;
            font-size: 14px;
            z-index: 10;
        `;
        
        btn.addEventListener('click', () => {
            this.startListening(input);
        });
        
        // Make input container relative
        const container = input.closest('.form-group');
        if (container) {
            container.style.position = 'relative';
        }
        
        return btn;
    }
    
    addLanguageSelector() {
        const header = document.querySelector('.header');
        if (!header) return;
        
        const langSelector = document.createElement('div');
        langSelector.className = 'voice-lang-selector';
        langSelector.innerHTML = `
            <select id="voiceLang">
                <option value="ru-RU">🇷🇺 Русский</option>
                <option value="en-US">🇺🇸 English</option>
                <option value="fi-FI">🇫🇮 Suomi</option>
            </select>
        `;
        
        langSelector.style.cssText = `
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 100;
        `;
        
        header.appendChild(langSelector);
        
        document.getElementById('voiceLang').addEventListener('change', (e) => {
            this.currentLanguage = e.target.value;
            if (this.recognition) {
                this.recognition.lang = this.currentLanguage;
            }
        });
    }
    
    addQuickTemplates() {
        const forms = document.querySelectorAll('form');
        
        forms.forEach(form => {
            const templatePanel = document.createElement('div');
            templatePanel.className = 'voice-templates';
            templatePanel.innerHTML = `
                <h4>🎯 Быстрые шаблоны:</h4>
                <div class="template-buttons"></div>
            `;
            
            templatePanel.style.cssText = `
                background: #f9fafb;
                padding: 15px;
                border-radius: 8px;
                margin: 15px 0;
                display: none;
            `;
            
            form.insertBefore(templatePanel, form.firstChild);
            
            // Add toggle button
            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'template-toggle';
            toggleBtn.innerHTML = '🎯 Шаблоны';
            toggleBtn.style.cssText = `
                background: #10b981;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 6px;
                cursor: pointer;
                margin: 10px 0;
            `;
            
            toggleBtn.addEventListener('click', () => {
                templatePanel.style.display = templatePanel.style.display === 'none' ? 'block' : 'none';
                this.populateTemplates(templatePanel);
            });
            
            form.insertBefore(toggleBtn, form.firstChild);
        });
    }
    
    populateTemplates(panel) {
        const buttonsContainer = panel.querySelector('.template-buttons');
        buttonsContainer.innerHTML = '';
        
        // Add template categories
        const categories = [
            { name: 'Проблемы', key: 'problems', color: '#ef4444' },
            { name: 'Модели', key: 'models', color: '#3b82f6' },
            { name: 'Фразы', key: 'phrases', color: '#10b981' }
        ];
        
        categories.forEach(category => {
            const categoryDiv = document.createElement('div');
            categoryDiv.innerHTML = `<h5 style="color: ${category.color}; margin: 10px 0 5px 0;">${category.name}:</h5>`;
            
            const templates = this.templates.get(category.key);
            templates.forEach(template => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = template;
                btn.className = 'template-btn';
                btn.style.cssText = `
                    background: ${category.color};
                    color: white;
                    border: none;
                    padding: 4px 8px;
                    border-radius: 4px;
                    cursor: pointer;
                    margin: 2px;
                    font-size: 12px;
                `;
                
                btn.addEventListener('click', () => {
                    this.applyTemplate(template);
                });
                
                categoryDiv.appendChild(btn);
            });
            
            buttonsContainer.appendChild(categoryDiv);
        });
    }
    
    startListening(input) {
        if (!this.recognition) {
            alert('Голосовой ввод не поддерживается в вашем браузере');
            return;
        }
        
        this.currentField = input;
        
        if (this.isListening) {
            this.recognition.stop();
        } else {
            this.recognition.start();
        }
    }
    
    processVoiceInput(text) {
        if (!this.currentField) return;
        
        // Clean and process the text
        let processedText = text.trim();
        
        // Apply smart formatting based on field type
        const fieldName = this.currentField.name || this.currentField.id;
        
        if (fieldName.includes('name') || fieldName.includes('client')) {
            processedText = this.formatName(processedText);
        } else if (fieldName.includes('phone')) {
            processedText = this.formatPhone(processedText);
        } else if (fieldName.includes('email')) {
            processedText = this.formatEmail(processedText);
        } else if (fieldName.includes('model') || fieldName.includes('device')) {
            processedText = this.formatDeviceModel(processedText);
        } else if (fieldName.includes('problem') || fieldName.includes('description')) {
            processedText = this.formatProblemDescription(processedText);
        }
        
        // Set the value
        this.currentField.value = processedText;
        
        // Trigger change event
        this.currentField.dispatchEvent(new Event('input', { bubbles: true }));
        
        // Show feedback
        this.showVoiceFeedback(processedText);
    }
    
    formatName(text) {
        return text.split(' ').map(word => 
            word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()
        ).join(' ');
    }
    
    formatPhone(text) {
        // Extract numbers
        const numbers = text.replace(/\D/g, '');
        
        // Format based on length
        if (numbers.length >= 10) {
            return '+358' + numbers.slice(-9);
        }
        
        return numbers;
    }
    
    formatEmail(text) {
        // Simple email formatting
        return text.toLowerCase().replace(/\s+/g, '') + '@gmail.com';
    }
    
    formatDeviceModel(text) {
        // Try to match with known models
        const models = this.templates.get('models');
        const matched = models.find(model => 
            model.toLowerCase().includes(text.toLowerCase()) ||
            text.toLowerCase().includes(model.toLowerCase())
        );
        
        return matched || text;
    }
    
    formatProblemDescription(text) {
        // Capitalize first letter
        return text.charAt(0).toUpperCase() + text.slice(1);
    }
    
    applyTemplate(template) {
        if (!this.currentField) {
            // Find the most relevant field
            const problemField = document.querySelector('textarea[name*="problem"], textarea[id*="problem"]');
            if (problemField) {
                this.currentField = problemField;
            }
        }
        
        if (this.currentField) {
            this.currentField.value = template;
            this.currentField.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
    
    showVoiceFeedback(text) {
        const feedback = document.createElement('div');
        feedback.className = 'voice-feedback';
        feedback.textContent = `✅ Распознано: "${text}"`;
        feedback.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 10px 15px;
            border-radius: 6px;
            z-index: 1000;
            font-size: 14px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        `;
        
        document.body.appendChild(feedback);
        
        setTimeout(() => {
            feedback.remove();
        }, 3000);
    }
    
    updateUI() {
        const voiceBtns = document.querySelectorAll('.voice-btn');
        
        voiceBtns.forEach(btn => {
            if (this.isListening) {
                btn.style.background = '#ef4444';
                btn.innerHTML = '🔴';
                btn.title = 'Остановить запись';
            } else {
                btn.style.background = '#1C5FDD';
                btn.innerHTML = '🎤';
                btn.title = 'Голосовой ввод';
            }
        });
    }
}

// Initialize voice input system
document.addEventListener('DOMContentLoaded', () => {
    if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
        new VoiceInputSystem();
    }
});

// Export for use in other scripts
window.VoiceInputSystem = VoiceInputSystem;
