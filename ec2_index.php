<?php
require 'vendor/autoload.php';

use Aws\CloudWatch\CloudWatchClient;
use Aws\Exception\AwsException;
use Aws\Ec2\Metadata\InstanceMetadataProvider; 

$timestamps = [];
$values = [];
$error_message = null;

try {
    // 1. AWS SDK 공식 기능으로 인스턴스 ID와 리전 가져오기
    $provider = new InstanceMetadataProvider();
    
    $my_instance_id = $provider->getInstanceId()->wait();
    $az = $provider->getAvailabilityZone()->wait(); 
    $my_region = substr($az, 0, -1);               

} catch (\Exception $e) {
    // 실패 시 디버깅용 기본값 (하드코딩 주소 탈출 실패 시 백업)
    $my_instance_id = "i-placeholder";
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
                            ['Name' => 'InstanceId', 'Value' => trim($my_instance_id)]
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
        
        <p style="color: #666; font-size: 12px;">ID: <?php echo htmlspecialchars($my_instance_id); ?></p>

        <?php if ($error_message !== null): ?>
            <div style="color: red; border: 1px solid red; padding: 10px;">
                AWS 에러 발생: <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php elseif (empty($timestamps)): ?>
            <div style="color: orange; border: 1px solid orange; padding: 10px;">
                아직 1분 단위 데이터가 세팅되지 않았습니다. 3~5분 후 새로고침 하세요.
            </div>
        <?php else: ?>
            <div style="background: #f9f9f9; padding: 20px; border-radius: 8px;">
                <canvas id="cpuChart"></canvas>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // 빈 배열이더라도 자바스크립트 문법 오류가 나지 않도록 처리
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
