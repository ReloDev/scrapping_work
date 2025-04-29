<?php
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use Symfony\Component\Panther\PantherTestCase;

class JijiCIScraper {
    private $visited = [];
    private $queue = [];
    private $phoneNumbers = [];
    private $pageCount = 0;
    private $numbersByPage = [];

    const BASE_URL = "https://jiji.co.ci";
    const USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36";
    const TIMEOUT = 30;
    const DELAY = 2; // 2 secondes entre les requêtes
    const MAX_PAGES = 100000000000000000000000000000000000000000000000000000;

    const EXCLUDE_PATHS = [
        "/auth", "/login", "/signup", "/user", "/logout",
        "/cart", "/checkout", "/account", "/admin",
        ".pdf", ".jpg", ".png", ".zip", ".doc", ".xls"
    ];

    const PHONE_REGEX = '/(?:\+225|00225|225|0)[\s\-\.]?[1579]\d[\s\-\.]?\d{2}[\s\-\.]?\d{2}[\s\-\.]?\d{2}/';

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
            curl_close($ch);
            throw new Exception('cURL error: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            throw new Exception("HTTP request failed with code $httpCode or empty content");
        }

        return $response;
    }

    public function is_valid_url(string $url): bool {
        $parsed = parse_url($url);
        if ($parsed === false) return false;

        if (!isset($parsed['host']) || !str_ends_with($parsed['host'], 'jiji.co.ci')) return false;

        if (isset($parsed['path'])) {
            foreach (self::EXCLUDE_PATHS as $ex) {
                if (str_contains($parsed['path'], $ex)) return false;
            }
        }

        $ext = pathinfo($parsed['path'] ?? '', PATHINFO_EXTENSION);
        if (in_array(strtolower($ext), ['pdf', 'jpg', 'png', 'zip'])) return false;

        return true;
    }

    private function normalize_url(string $url): string {
        if (strpos($url, 'http') !== 0) {
            $url = rtrim(self::BASE_URL, '/') . '/' . ltrim($url, '/');
        }

        $url = strtok($url, '#');
        $url = strtok($url, '?');

        return rtrim($url, '/');
    }

    public function extract_links(string $content, string $baseUrl): array {
        $links = [];

        preg_match_all('/<a\s+[^>]*href=(["\'])(?<url>.*?)\1/is', $content, $matches);
        if (!empty($matches['url'])) {
            foreach ($matches['url'] as $href) {
                $normalized = $this->normalize_url($href);
                if ($this->is_valid_url($normalized)) {
                    $links[] = $normalized;
                }
            }
        }

        try {
            $dom = new DOMDocument();
            @$dom->loadHTML($content);

            foreach ($dom->getElementsByTagName('a') as $link) {
                $href = $link->getAttribute('href');
                $normalized = $this->normalize_url($href);
                if ($this->is_valid_url($normalized)) {
                    $links[] = $normalized;
                }
            }
        } catch (Exception $e) {
            error_log("DOM parse error: " . $e->getMessage());
        }

        return array_unique($links);
    }

    public function extract_phone_numbers(string $content): array {
        $numbers = [];
        preg_match_all(self::PHONE_REGEX, $content, $matches);
        $numbers = array_merge($numbers, $matches[0]);

        $dom = new DOMDocument();
        @$dom->loadHTML($content);
        $xpath = new DOMXPath($dom);
        $elements = $xpath->query("//*[contains(@class, 'phone') or contains(@class, 'contact') or contains(@class, 'number')]");

        foreach ($elements as $el) {
            preg_match(self::PHONE_REGEX, $el->textContent, $match);
            if (!empty($match[0])) {
                $numbers[] = $match[0];
            }
        }

        preg_match_all('/data-(?:phone|contact|number)=["\']([^"\']+)/i', $content, $dataMatches);
        foreach ($dataMatches[1] as $dataNum) {
            preg_match(self::PHONE_REGEX, $dataNum, $match);
            if (!empty($match[0])) {
                $numbers[] = $match[0];
            }
        }

        return array_unique($numbers);
    }

    public function scrape_page(string $url): void {
        if ($this->pageCount >= self::MAX_PAGES) return;
        if (in_array($url, $this->visited)) return;

        try {
            echo "⏳ Visiting: $url\n";
            $startTime = microtime(true);

            $content = $this->makeRequest($url);
            if (empty($content)) throw new Exception("Empty page content");

            $this->visited[] = $url;
            $this->pageCount++;

            $numbers = $this->extract_phone_numbers($content);
            if (!empty($numbers)) {
                echo "📞 Found " . count($numbers) . " numbers\n";
                $this->phoneNumbers = array_merge($this->phoneNumbers, $numbers);
                $this->numbersByPage[$url] = $numbers;
            }

            $links = $this->extract_links($content, $url);
            foreach ($links as $link) {
                if (!in_array($link, $this->visited) && !in_array($link, $this->queue)) {
                    $this->queue[] = $link;
                }
            }

            $elapsed = microtime(true) - $startTime;
            $delay = max(0, self::DELAY - $elapsed);
            if ($delay > 0) usleep($delay * 1_000_000);
        } catch (Exception $e) {
            echo "⚠ Error on $url: " . $e->getMessage() . "\n";
        }
    }

    public function run(): void {
        echo "🚀 Starting scraper with delay of " . self::DELAY . "s...\n";
        sleep(2); // Délai initial pour stabilité réseau

        while (!empty($this->queue) && $this->pageCount < self::MAX_PAGES) {
            $currentUrl = array_shift($this->queue);

            printf(
                "\r🔍 Pages: %d/%d | Queue: %d | Numbers: %d",
                $this->pageCount,
                self::MAX_PAGES,
                count($this->queue),
                count($this->phoneNumbers)
            );

            $this->scrape_page($currentUrl);
        }

        $finalNumbers = array_values(array_unique($this->phoneNumbers));
        sort($finalNumbers);

        echo "\n\n📊 Final results (" . count($finalNumbers) . " unique numbers):\n";
        $this->save_results($finalNumbers);

        $sample = array_slice($finalNumbers, 0, 20);
        echo implode("\n", $sample) . "\n...\n";
    }

    private function save_results(array $numbers): void {
        if (!file_exists('data')) mkdir('data');

        $jsonFile = 'data/jiji_ci_numbers.json';
        $jsonByPageFile = 'data/jiji_ci_numbers_by_page.json';
        $csvFile = 'data/jiji_ci_numbers.csv';

        file_put_contents($jsonFile, json_encode($numbers, JSON_PRETTY_PRINT));
        file_put_contents($jsonByPageFile, json_encode($this->numbersByPage, JSON_PRETTY_PRINT));
        
        $csv = fopen($csvFile, 'w');
        fputcsv($csv, ['phone_number']);
        foreach ($numbers as $num) {
            fputcsv($csv, [$num]);
        }
        fclose($csv);

        echo "\n💾 Data saved in 'data/' directory:\n";
        echo "- $jsonFile\n- $jsonByPageFile\n- $csvFile\n";

        // Envoi des résultats par email
        $this->send_results_by_email([$jsonFile, $jsonByPageFile, $csvFile]);
    }

    private function send_results_by_email(array $files): void {
        $mail = new PHPMailer(true);
        
        try {
            // Configuration SMTP
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'ayenaaurel15@gmail.com'; // Remplacez par votre email
            $mail->Password = 'xrdsosewvqwfakru'; // Remplacez par votre mot de passe
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;
            
            // Expéditeur
            $mail->setFrom('ayenaaurel15@gmail.com', 'Jiji CI Scraper');
            
            // Destinataires
            $mail->addAddress('ayenaaurel15@gmail.com');
            // $mail->addAddress('juniornonfon@gmail.com');
            
            // Sujet et corps
            $mail->Subject = 'Résultats du scraping Jiji.ci - ' . date('Y-m-d H:i:s');
            $mail->Body = sprintf(
                "Bonjour,\n\n".
                "Veuillez trouver ci-joint les résultats du scraping Jiji.ci :\n".
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
}

// Exécution du scraper
$scraper = new JijiCIScraper();
$scraper->run();