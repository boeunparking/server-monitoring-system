<?php
require 'vendor/autoload.php';

use Aws\CloudWatch\CloudWatchClient;
use Aws\Exception\AwsException;

$timestamps = [];
$values = [];
$error_message = null;

try {
    // 1. 컬을 이용해 IMDSv2 보안 토큰을 수동으로 확실하게 획득 (가장 안전한 우회로)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://169.254.169.254/latest/api/token");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-aws-ec2-metadata-token-ttl-seconds: 21600'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2초 타임아웃 제한
    $token = curl_exec($ch);

    if ($token) {
        // 토큰을 헤더에 실어서 인스턴스 ID 안전하게 탈취
        curl_setopt($ch, CURLOPT_URL, "http://169.254.169.254/latest/meta-data/instance-id");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("X-aws-ec2-metadata-token: $token"));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        $my_instance_id = trim(curl_exec($ch));

        // 가용 구역 정보 가져와서 리전 명 정제 (ap-northeast-2a -> ap-northeast-2)
        curl_setopt($ch, CURLOPT_URL, "http://169.254.169.254/latest/meta-data/placement/availability-zone");
        $az = trim(curl_exec($ch));
        $my_region = substr($az, 0, -1);
    } else {
        // 로컬 환경이거나 토큰 획득 실패 시 백업용 기본값
        $my_instance_id = "MY_INSTANCE_ID"; // 되는 코드의 실제 ID 백업
        $my_region = "ap-northeast-2";
    }
    curl_close($ch);

} catch (\Exception $e) {
    $my_instance_id = "MY_INSTANCE_ID";
    $my_region = "ap-northeast-2";
}

// 2. CloudWatch 클라이언트 생성
$cwClient = new CloudWatchClient([
    'region'  => $my_region,
    'version' => 'latest'
]);

try {
    $result = $cwClient->getMetricData([
        'MetricDataQueries' => [
            [
                'Id' => 'm1',
                'MetricStat' => [
                    'Metric' => [
                        'Namespace' => 'AWS/EC2',
                        'MetricName' => 'CPUUtilization',
                        'Dimensions' => [
                            ['Name' => 'InstanceId', 'Value' => $my_instance_id]
                        ]
                    ],
                    'Period' => 60, 
                    'Stat' => 'Average',
                ],
            ],
        ],
        'StartTime' => strtotime('-3 hours'), 
        'EndTime' => time(),
    ]);

    if (!empty($result['MetricDataResults'][0]['Timestamps'])) {
        foreach ($result['MetricDataResults'][0]['Timestamps'] as $dt) {
            $timestamps[] = $dt->format('H:i'); 
        }
        $timestamps = array_reverse($timestamps);
        $values = array_reverse($result['MetricDataResults'][0]['Values']);
    }

} catch (AwsException $e) {
    $error_message = $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>EC2 CPU 모니터링</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div style="width: 600px; margin: 50px auto; text-align: center; font-family: sans-serif;">
        <h2>🖥️ EC2 CPU 사용량 (1분 단위)</h2>
        <p style="color: #888; font-size: 12px;">조회중인 ID: <?php echo htmlspecialchars($my_instance_id); ?></p>
        
        <?php if ($error_message): ?>
            <div style="color: red; border: 1px solid red; padding: 10px;">
                AWS 에러 발생: <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php elseif (empty($timestamps)): ?>
            <div style="color: orange; border: 1px solid orange; padding: 10px;">
                아직 1분 단위 데이터가 세팅되지 않았습니다. 3~5분 후 새로고침 하세요.
            </div>
        <?php else: ?>
            <canvas id="cpuChart"></canvas>
        <?php endif; ?>
    </div>

    <script>
        const chartLabels = <?php echo json_encode($timestamps); ?>;
        const chartData = <?php echo json_encode($values); ?>;

        if (chartLabels && chartLabels.length > 0) {
            const ctx = document.getElementById('cpuChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'CPU 사용량 (%)',
                        data: chartData,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        borderWidth: 2,
                        tension: 0.1
                    }]
                },
                options: {
                    scales: { y: { beginAtZero: true, max: 100 } }
                }
            });
        }
    </script>
</body>
</html>
