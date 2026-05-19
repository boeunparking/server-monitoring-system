<?php
require 'vendor/autoload.php';

use Aws\CloudWatch\CloudWatchClient;
use Aws\Exception\AwsException;

$cwClient = new CloudWatchClient([
    'region'  => 'ap-northeast-2', 
    'version' => 'latest'
    // 'credentials' 항목을 아예 통째로 지워버립니다!
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
                            ['Name' => 'InstanceId', 'Value' => 'instance-id']
                        ]
                    ],
                    'Period' => 60, // 1분 단위
                    'Stat' => 'Average',
                ],
            ],
        ],
        'StartTime' => strtotime('-3 hours'), //최근 3시간 치
        'EndTime' => time(),
    ]);

// 데이터가 존재하는지 안전하게 체크
    if (!empty($result['MetricDataResults'][0]['Timestamps'])) {
        foreach ($result['MetricDataResults'][0]['Timestamps'] as $dt) {
            $timestamps[] = $dt->format('H:i'); 
        }
        // 과거 -> 최신순으로 정렬
        $timestamps = array_reverse($timestamps);
        $values = array_reverse($result['MetricDataResults'][0]['Values']);
    }

} catch (AwsException $e) {
    echo "에러 발생: " . $e->getMessage();
}
?>


<!DOCTYPE html>
<html>
<head>
    <title>EC2 CPU 모니터링</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div style="width: 600px; margin: 50px auto; text-align: center;">
        <h2>🖥️ EC2 CPU 사용량 (1분 단위)</h2>
        
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

        if (chartLabels.length > 0) {
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
