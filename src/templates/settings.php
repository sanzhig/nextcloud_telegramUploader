<!-- templates/settings.php -->
<style>
    form {
        width: 100%;
        margin: 0 auto;
    }
    .form-group {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
        justify-content: left;
    }
    label {
        width: 30%; /* Устанавливает ширину меток */
        text-align: right;
        margin-right: 10px;
    }
    input[type="text"],
    input[type="number"]
    {
        width: 65%; /* Устанавливает ширину полей ввода */
    }
    .submit-button {
        text-align: center;
        margin-top: 10px;
    }
    input[type="submit"] {
        cursor: pointer;
    }
</style>

<form action="<?php p($_['urlGenerator']->linkToRoute('telegramuploader.settings.save')) ?>" method="post">
    <div class="form-group">
        <label for="bot_token">bot_token</label>
        <input type="text" name="bot_token" id="bot_token" value="<?php p($_['bot_token']) ?>" required>
    </div>
    <div class="form-group">
        <label for="chatId">chatId</label>
        <input type="number" name="chatId" id="chatId" value="<?php p($_['chatId']) ?>" required>
    </div>
    <div class="form-group">
        <label for="tgRootPath">Download to folder:</label>
        <input type="text" name="tgRootPath" id="tgRootPath" value="<?php p($_['tgRootPath'])  ?>" required>
    </div>
    <div class="form-group">
        <label for="isSeparateFolder">separateByType</label>
        <input type="checkbox" name="isSeparateFolder" id="isSeparateFolder" value="1" <?php if ($_['isSeparateFolder']) { ?>checked<?php } ?>>
    </div>
    <div class="submit-button">
        <input type="submit" value="Save">
        <label><?php p($_['webhook_result']) ?></label>
    </div>
</form>

