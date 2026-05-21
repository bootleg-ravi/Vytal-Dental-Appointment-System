<?php
class ErrorHandler {
    private $errors = [];

    public function addError($field, $message) {
        $this->errors[$field] = $message;
    }
    
    public function hasErrors() {
        return !empty($this->errors);
    }
    
    public function getErrors() {
        return $this->errors;
    }
    
    public function getError($field) {
        return $this->errors[$field] ?? null;
    }
    
    public function getFirstError() {
        return !empty($this->errors) ? reset($this->errors) : null;
    }
    
    public function getErrorsAsHTML() {
        if (empty($this->errors)) {
            return '';
        }
        
        $html = '<ul class="list-disc list-inside">';
        foreach ($this->errors as $field => $message) {
            $html .= '<li>' . htmlspecialchars($message) . '</li>';
        }
        $html .= '</ul>';
        
        return $html;
    }

    public function clearErrors() {
        $this->errors = [];
    }
}
?>
