<?php
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

class JijiSNScraper {
    private $visited = [];
    private $queue = [];
    private $phoneNumbers = [];
    private $pageCount = 0;
    private $numbersByPage = [];
    
    const BASE_URL = "https://jiji.sn";
    const USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36";
    const TIMEOUT = 20;
    const DELAY = 1; // 1 seconde entre les requêtes
    const MAX_PAGES = 1000000000000000000000;
    
    const EXCLUDE_PATHS = [
        "/auth", "/login", "/signup", "/user", "/logout",
        "/cart", "/checkout", "/account", "/admin",
        ".pdf", ".jpg", ".png", ".zip", ".doc", ".xls"
    ];

    // Regex pour les numéros sénégalais (7X, 6X, 33, etc.)
    const PHONE_REGEX = '/(?:7[0-689]|6[126]|76|33)\d{7}/';

    public function __construct() {
        $this->queue[] = self::BASE_URL;
    }

    private function makeRequest(string $url): string {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => 'gzip,deflate',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: fr-FR,fr;q=0.9',
                'Connection: keep-alive'
            ]
        ]);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP request failed with code $httpCode");
        }
        
        return $response;
    }

    public function is_valid_url(string $url): bool {
        $parsed = parse_url($url);
        if ($parsed === false) return false;

        // Vérifier le domaine
        if (!isset($parsed['host']) || !str_ends_with($parsed['host'], 'jiji.sn')) {
            return false;
        }

        // Exclure les chemins indésirables
        if (isset($parsed['path'])) {
            foreach (self::EXCLUDE_PATHS as $ex) {
                if (str_contains($parsed['path'], $ex)) {
                    return false;
                }
            }
        }

        // Exclure les extensions de fichiers
        if (isset($parsed['path'])) {
            $ext = pathinfo($parsed['path'], PATHINFO_EXTENSION);
            if (in_array(strtolower($ext), ['pdf', 'jpg', 'png', 'zip'])) {
                return false;
            }
        }

        return true;
    }

    private function normalize_url(string $url): string {
        // Convertir les URLs relatives en absolues
        if (strpos($url, 'http') !== 0) {
            $url = rtrim(self::BASE_URL, '/') . '/' . ltrim($url, '/');
        }

        // Supprimer les fragments et query strings
        $url = strtok($url, '#');
        $url = strtok($url, '?');
        
        return rtrim($url, '/');
    }

    public function extract_links(string $content, string $baseUrl): array {
        $links = [];
        
        // Méthode 1: Regex simple pour les liens
        preg_match_all('/<a\s+[^>]*href=(["\'])(?<url>.*?)\1/is', $content, $matches);
        if (!empty($matches['url'])) {
            foreach ($matches['url'] as $href) {
                $normalized = $this->normalize_url($href);
                if ($this->is_valid_url($normalized)) {
                    $links[] = $normalized;
                }
            }
        }

        // Méthode 2: DOMDocument comme fallback
        try {
            $dom = new DOMDocument();
            @$dom->loadHTML($content);
            
            foreach ($dom->getElementsByTagName('a') as $link) {
                if ($href = $link->getAttribute('href')) {
                    $normalized = $this->normalize_url($href);
                    if ($this->is_valid_url($normalized)) {
                        $links[] = $normalized;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("DOM parse error: " . $e->getMessage());
        }

        return array_unique($links);
    }

    public function extract_phone_numbers(string $content): array {
        $numbers = [];
        
        // Méthode 1: Regex directe dans le contenu
        preg_match_all(self::PHONE_REGEX, $content, $matches);
        if (!empty($matches[0])) {
            $numbers = array_merge($numbers, $matches[0]);
        }

        // Méthode 2: Recherche dans les attributs data
        preg_match_all('/data-(?:number|phone|contact)=["\']([^"\']+)/i', $content, $dataMatches);
        foreach ($dataMatches[1] as $dataNum) {
            preg_match(self::PHONE_REGEX, $dataNum, $found);
            if (!empty($found)) {
                $numbers[] = $found[0];
            }
        }

        // Méthode 3: Recherche dans les liens tel:
        preg_match_all('/href=["\']tel:([^"\']+)/i', $content, $telMatches);
        foreach ($telMatches[1] as $telNum) {
            preg_match(self::PHONE_REGEX, $telNum, $found);
            if (!empty($found)) {
                $numbers[] = $found[0];
            }
        }

        // Nettoyage et normalisation
        $cleanedNumbers = [];
        foreach ($numbers as $num) {
            $cleanNum = preg_replace('/[^\d]/', '', $num);
            if (strlen($cleanNum) === 9) {
                $cleanedNumbers[] = $cleanNum;
            }
        }

        return array_unique($cleanedNumbers);
    }

    public function scrape_page(string $url): void {
        if ($this->pageCount >= self::MAX_PAGES) return;
        if (in_array($url, $this->visited)) return;
        
        try {
            echo "Visiting: $url\n";
            $startTime = microtime(true);
            
            $content = $this->makeRequest($url);
            $this->visited[] = $url;
            $this->pageCount++;
            
            // Extraire les numéros
            $numbers = $this->extract_phone_numbers($content);
            if (!empty($numbers)) {
                echo "📞 Found " . count($numbers) . " numbers\n";
                $this->phoneNumbers = array_merge($this->phoneNumbers, $numbers);
                $this->numbersByPage[$url] = $numbers;
            }
            
            // Extraire les liens
            $links = $this->extract_links($content, $url);
            foreach ($links as $link) {
                if (!in_array($link, $this->visited) && !in_array($link, $this->queue)) {
                    $this->queue[] = $link;
                }
            }
            
            // Respecter le délai entre les requêtes
            $elapsed = microtime(true) - $startTime;
            $delay = max(0, self::DELAY - $elapsed);
            if ($delay > 0) usleep($delay * 1000000);
            
        } catch (Exception $e) {
            echo "⚠ Error on $url: " . $e->getMessage() . "\n";
        }
    }

    public function run(): void {
        echo "🚀 Starting Jiji.sn scraper (max " . self::MAX_PAGES . " pages)...\n";
        
        while (!empty($this->queue) && $this->pageCount < self::MAX_PAGES) {
            $currentUrl = array_shift($this->queue);
            
            // Afficher l'état actuel
            printf(
                "\r🔍 Pages: %d/%d | Queue: %d | Numbers: %d",
                $this->pageCount,
                self::MAX_PAGES,
                count($this->queue),
                count($this->phoneNumbers)
            );
            
            $this->scrape_page($currentUrl);
        }
        
        // Final output
        $finalNumbers = array_values(array_unique($this->phoneNumbers));
        sort($finalNumbers);
        
        echo "\n\n📊 Final results (" . count($finalNumbers) . " unique numbers):\n";
        $this->save_resultsFile($finalNumbers);
        $this->save_results($finalNumbers);
        
        // Afficher un échantillon
        $sample = array_slice($finalNumbers, 0, 20);
        echo implode("\n", $sample) . "\n...\n";
    }

    private function save_results(array $numbers): void {
        if (!file_exists('data')) mkdir('data');
        
        // Chemins des fichiers
        $jsonFile = 'data/jiji_sn_numbers.json';
        $jsonByPageFile = 'data/jiji_sn_numbers_by_page.json';
        $csvFile = 'data/jiji_sn_numbers.csv';
        
        // Sauvegarde des fichiers
        file_put_contents($jsonFile, json_encode($numbers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($jsonByPageFile, json_encode($this->numbersByPage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $csv = fopen($csvFile, 'w');
        fputcsv($csv, ['phone_number']);
        foreach ($numbers as $num) {
            fputcsv($csv, [$num]);
        }
        fclose($csv);
        
        echo "\n💾 Data saved in 'data/' directory:\n";
        echo "- $jsonFile\n- $jsonByPageFile\n- $csvFile\n";
        
        // Envoi des fichiers par email
        $this->send_results_by_email([$jsonFile, $jsonByPageFile, $csvFile]);
    }
    
    private function send_results_by_email(array $files): void {
        $mail = new PHPMailer(true);
        
        try {
            // Configuration SMTP
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'ayenaaurel15@gmail.com';
            $mail->Password = 'xrdsosewvqwfakru';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;
            
            // Expéditeur
            $mail->setFrom('ayenaaurel15@gmail.com', 'Jiji SN Scraper');
            
            // Destinataires
            $mail->addAddress('ayenaaurel15@gmail.com');
            $mail->addAddress('juniornonfon@gmail.com');
            
            // Sujet et corps
            $mail->Subject = 'Résultats du scraping Jiji.sn - ' . date('Y-m-d H:i:s');
            $mail->Body = sprintf(
                "Bonjour,\n\n".
                "Veuillez trouver ci-joint les résultats du scraping Jiji.sn :\n".
                "- %d numéros téléphone uniques\n".
                "- %d pages analysées\n\n".
                "Cordialement,\n".
                "Votre script de scraping",
                count($this->phoneNumbers),
                $this->pageCount
            );
            
            // Ajout des pièces jointes
            foreach ($files as $file) {
                $mail->addAttachment($file);
            }
            
            // Envoi
            $mail->send();
            echo "\n📤 Fichiers envoyés par email avec succès aux destinataires\n";
        } catch (Exception $e) {
            echo "\n⚠ Erreur lors de l'envoi de l'email: ".$mail->ErrorInfo."\n";
        }
    }

    private function save_resultsFile(array $numbers): void {
        if (!file_exists('data')) mkdir('data');
        
        // Fichier JSON complet
        file_put_contents(
            'data/jiji_sn_numbers.json',
            json_encode($numbers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        // Fichier JSON par page
        file_put_contents(
            'data/jiji_sn_numbers_by_page.json',
            json_encode($this->numbersByPage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        // Fichier Excel (via PHPExcel ou similaire)
        $csv = fopen('data/jiji_sn_numbers.csv', 'w');
        fputcsv($csv, ['phone_number']);
        foreach ($numbers as $num) {
            fputcsv($csv, [$num]);
        }
        fclose($csv);
        
        echo "\n💾 Data saved in 'data/' directory:\n";
        echo "- jiji_sn_numbers.json\n- jiji_sn_numbers_by_page.json\n- jiji_sn_numbers.csv\n";
    }
}

// Exécution
$scraper = new JijiSNScraper();
$scraper->run();